<?php

namespace App\Ecu\Xdf;

use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;
use SimpleXMLElement;

/**
 * Reads TunerPro XDF definitions (tables and constants with embedded data) into MapDefs.
 * mmedtypeflags: 0x01 signed, 0x02 LSB first (little-endian), 0x04 column-major, 0x10000 float.
 */
class XdfImporter
{
    /** @var string[] warnings collected during import */
    public array $warnings = [];

    /**
     * @return array{title: string, maps: MapDef[]}
     */
    public function import(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            throw new \InvalidArgumentException('Not a valid XDF (XML) file.');
        }

        $header = $doc->XDFHEADER;
        $baseOffset = (int) self::num((string) ($header->BASEOFFSET['offset'] ?? '0'));
        if ((string) ($header->BASEOFFSET['subtract'] ?? '0') === '1') {
            $baseOffset = -$baseOffset;
        }
        $defaultLe = (string) ($header->DEFAULTS['lsbfirst'] ?? '1') === '1';

        $categories = [];
        foreach ($header->CATEGORY as $cat) {
            $categories[(int) self::num((string) $cat['index']) + 1] = (string) $cat['name'];
        }

        $maps = [];
        foreach ($doc->XDFTABLE as $table) {
            $map = $this->table($table, $categories, $baseOffset, $defaultLe);
            if ($map) {
                $maps[] = $map;
            }
        }
        foreach ($doc->XDFCONSTANT as $const) {
            $map = $this->constant($const, $categories, $baseOffset, $defaultLe);
            if ($map) {
                $maps[] = $map;
            }
        }

        return ['title' => (string) $header->deftitle, 'maps' => $maps];
    }

    /**
     * @param  array<int, string>  $categories
     */
    private function table(SimpleXMLElement $table, array $categories, int $baseOffset, bool $defaultLe): ?MapDef
    {
        $axes = [];
        foreach ($table->XDFAXIS as $axis) {
            $axes[(string) $axis['id']] = $axis;
        }
        $z = $axes['z'] ?? null;
        if ($z === null || ! isset($z->EMBEDDEDDATA['mmedaddress'])) {
            return null;
        }
        $e = $z->EMBEDDEDDATA;
        [$dtype, $be, $colMajor] = $this->storage($e, $defaultLe);
        $title = trim((string) $table->title);
        $math = $this->math($z, $title);

        $map = new MapDef(
            name: $title !== '' ? $title : 'Table',
            address: (int) self::num((string) $e['mmedaddress']) + $baseOffset,
            rows: max(1, (int) ($e['mmedrowcount'] ?? 1)),
            cols: max(1, (int) ($e['mmedcolcount'] ?? 1)),
            dtype: $dtype,
            be: $be,
            factor: $math['factor'],
            offset: $math['offset'],
            unit: trim((string) $z->units),
            category: $this->category($table, $categories),
            description: trim((string) $table->description),
            source: 'xdf',
            confidence: 1.0,
            colMajor: $colMajor,
            decimals: (int) ($z->decimalpl ?? 2),
        );
        $map->x = isset($axes['x']) ? $this->axis($axes['x'], $map->cols, $baseOffset, $defaultLe) : null;
        $map->y = isset($axes['y']) ? $this->axis($axes['y'], $map->rows, $baseOffset, $defaultLe) : null;

        return $map;
    }

    /**
     * @param  array<int, string>  $categories
     */
    private function constant(SimpleXMLElement $const, array $categories, int $baseOffset, bool $defaultLe): ?MapDef
    {
        $e = $const->EMBEDDEDDATA;
        if (! isset($e['mmedaddress'])) {
            return null;
        }
        [$dtype, $be] = $this->storage($e, $defaultLe);
        $title = trim((string) $const->title);
        $math = $this->math($const, $title);

        return new MapDef(
            name: $title !== '' ? $title : 'Constant',
            address: (int) self::num((string) $e['mmedaddress']) + $baseOffset,
            dtype: $dtype,
            be: $be,
            factor: $math['factor'],
            offset: $math['offset'],
            unit: trim((string) $const->units),
            category: $this->category($const, $categories),
            description: trim((string) $const->description),
            source: 'xdf',
            confidence: 1.0,
            decimals: (int) ($const->decimalpl ?? 2),
        );
    }

    private function axis(SimpleXMLElement $axis, int $count, int $baseOffset, bool $defaultLe): ?Axis
    {
        $e = $axis->EMBEDDEDDATA;
        $math = $this->math($axis, 'axis');
        $unit = trim((string) $axis->units);
        $decimals = (int) ($axis->decimalpl ?? 0);

        if (isset($e['mmedaddress']) && (int) ($e['mmedelementsizebits'] ?? 0) > 0) {
            [$dtype, $be] = $this->storage($e, $defaultLe);

            return new Axis($count, (int) self::num((string) $e['mmedaddress']) + $baseOffset, $dtype, $be,
                $math['factor'], $math['offset'], $unit, '', null, $decimals);
        }

        // Fixed label axis
        $labels = [];
        foreach ($axis->LABEL as $label) {
            $labels[(int) $label['index']] = (float) $label['value'];
        }
        ksort($labels);
        if (count($labels) >= $count && $count > 1) {
            return new Axis($count, null, 'u16', false, 1.0, 0.0, $unit, '', array_slice(array_values($labels), 0, $count), $decimals);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: bool, 2: bool} dtype, bigEndian, colMajor
     */
    private function storage(SimpleXMLElement $e, bool $defaultLe): array
    {
        $flags = (int) self::num((string) ($e['mmedtypeflags'] ?? '0'));
        $bits = (int) ($e['mmedelementsizebits'] ?? 8);
        $signed = (bool) ($flags & 0x01);
        $le = $bits === 8 ? true : (bool) ($flags & 0x02) || ($defaultLe && ! isset($e['mmedtypeflags']));
        $dtype = match (true) {
            ($flags & 0x10000) && $bits === 32 => 'f32',
            $bits === 32 => $signed ? 's32' : 'u32',
            $bits === 16 => $signed ? 's16' : 'u16',
            default => $signed ? 's8' : 'u8',
        };

        return [$dtype, ! $le, (bool) ($flags & 0x04)];
    }

    /**
     * @return array{factor: float, offset: float}
     */
    private function math(SimpleXMLElement $node, string $title): array
    {
        $equation = (string) ($node->MATH['equation'] ?? 'X');
        $linear = LinearEquation::parse($equation);
        if ($linear === null) {
            $this->warnings[] = "{$title}: non-linear equation \"{$equation}\" imported as raw values";

            return ['factor' => 1.0, 'offset' => 0.0];
        }

        return $linear;
    }

    /**
     * @param  array<int, string>  $categories
     */
    private function category(SimpleXMLElement $node, array $categories): string
    {
        $mem = $node->CATEGORYMEM[0] ?? null;
        if ($mem === null) {
            return 'XDF';
        }

        return $categories[(int) self::num((string) $mem['category'])] ?? 'XDF';
    }

    private static function num(string $s): int|float
    {
        $s = trim($s);

        return str_starts_with(strtolower($s), '0x') ? hexdec(substr($s, 2)) : (is_numeric($s) ? $s + 0 : 0);
    }
}
