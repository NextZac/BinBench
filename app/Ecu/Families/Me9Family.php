<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Checksum\Me9Checksum;
use App\Ecu\Families\Me9\Me9MapFinder;
use App\Ecu\IdStrings;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\AxisScanner;
use App\Ecu\Maps\MapDef;

/**
 * Bosch ME9.0 gasoline (Volvo five-cylinder turbo, Ford Focus RS mk2): Freescale MPC5xx PowerPC,
 * big-endian, 2 MB flash. File offset == flash address; calibration segment around 0x1C0000.
 */
class Me9Family implements EcuFamily
{
    public function id(): string
    {
        return 'me9';
    }

    public function detect(Bin $bin): ?array
    {
        $variant = IdStrings::first($bin, '#\d+/\d+/(ME9\d[A-Z]?)/#') ?? IdStrings::first($bin, '/Bosch\.Common\.RamLoader\.(Me9)\./');
        if ($variant === null) {
            return null;
        }

        $score = 60;
        $hw = IdStrings::first($bin, '/(0261\d{6})/');
        $sw = IdStrings::first($bin, '/(1037\d{6})/');
        $score += $hw ? 20 : 0;
        $score += $sw ? 10 : 0;
        $score += $bin->size === 0x200000 ? 10 : 0;

        $ident = IdStrings::first($bin, '#(\d+/\d+/ME9\d[A-Z]?/[\x20-\x7E]{10,80})#');
        $dataset = $ident !== null ? (explode('/', $ident)[6] ?? null) : null;
        $engine = $ident !== null && preg_match('#/I(\d)(\d)(\d)#', $ident, $m) ? "{$m[2]}.{$m[3]}L R{$m[1]}" : null;

        $notes = [];
        if ($ident !== null) {
            $notes[] = 'Ident '.rtrim($ident, '/');
        }
        if ($bin->size !== 0x200000) {
            $notes[] = 'Not a full 2 MB read: map and checksum detection may be incomplete.';
        }

        return array_filter([
            'score' => min(100, $score),
            'family' => 'Bosch ME9.0',
            'variant' => str_starts_with(strtoupper($variant), 'ME90') ? $variant : 'ME9.0',
            'cpu' => 'MPC5xx (PowerPC)',
            'endian' => 'be',
            'hw' => $hw,
            'sw' => $sw,
            'swVersion' => $dataset,
            'engine' => $engine,
            'fuel' => 'Petrol',
            'notes' => $notes,
        ], fn ($v) => $v !== null);
    }

    public function findMaps(Bin $bin, array $ident): array
    {
        if (class_exists(Me9MapFinder::class)) {
            $maps = (new Me9MapFinder)->find($bin);
            if ($maps) {
                return $maps;
            }
        }

        return $this->scanCalibration($bin);
    }

    public function checksum(Bin $bin): ?ChecksumModule
    {
        if (! class_exists(Me9Checksum::class)) {
            return null;
        }
        $module = new Me9Checksum;
        try {
            return $module->supports($bin) ? $module : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fallback without code analysis: unnamed inline-axis tables from the calibration segment.
     *
     * @return MapDef[]
     */
    private function scanCalibration(Bin $bin): array
    {
        [$start, $end] = $this->calibrationRange($bin);
        $maps = [];
        foreach ((new AxisScanner($bin, true, $start, $end))->scan() as $c) {
            $map = new MapDef(
                name: ($c['rows'] > 1 ? 'Map' : 'Curve').' '.$c['rows'].'x'.$c['cols'],
                address: $c['address'],
                rows: $c['rows'],
                cols: $c['cols'],
                be: true,
                category: 'Unidentified',
                description: sprintf('Potential map found by structure scan. Layout %s, header at 0x%X.', $c['layout'], $c['header']),
                confidence: 0.2,
                decimals: 0,
            );
            $map->x = new Axis($c['xCount'], $c['xAddr'], 'u16', true);
            $map->y = $c['yAddr'] !== null ? new Axis($c['yCount'], $c['yAddr'], 'u16', true) : null;
            $maps[] = $map;
        }

        return $maps;
    }

    /**
     * Calibration segment from its header (0x5A5A5A5A 0xCCCCCCCC ... end address), else the 0x1C0000 block.
     *
     * @return array{0: int, 1: int}
     */
    private function calibrationRange(Bin $bin): array
    {
        foreach ($bin->findPattern('5A 5A 5A 5A CC CC CC CC', 0, 4) as $header) {
            $end = $bin->u32($header + 0x1C, true);
            if ($end > $header && $end <= $bin->size) {
                return [$header, $end];
            }
        }

        return $bin->size >= 0x1E0000 ? [0x1C0000, 0x1E0000] : [0, $bin->size];
    }
}
