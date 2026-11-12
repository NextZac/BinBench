<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Generic "potential map" finder (WinOLS-style) for 16-bit calibration tables with inline axes.
 *
 * Recognised layouts (W = 16-bit word, LE or BE):
 *   A  [id] n  X[n] [id] m  Y[m] Z[n*m]     EDC15 (VAG: no id words, BMW DDE4: RAM-address id before each count)
 *   B  n  m  X[n] Y[m] Z[n][m]              EDC16 / EDC17 / MED17 (Z runs along the second axis first)
 *   C  [id] n  X[n] Z[n]                    curves
 * An axis is a count followed by strictly increasing values. In layout B one axis may be a plain
 * index 0..n-1 (e.g. gear 0..7 on EDC16 per-gear limiters) when the other axis is a real one.
 */
class AxisScanner
{
    public const MAX_AXIS = 48;

    /** Longest plain index axis (0, 1, 2 ...) accepted next to a real axis: gears, cylinders, modes. */
    private const MAX_INDEX_AXIS = 10;

    /** @var int[] 0-indexed words */
    private array $w;

    private int $n;

    public function __construct(private Bin $bin, private bool $be = false, private int $start = 0, private ?int $end = null)
    {
        $this->end ??= $bin->size;
        $chunk = substr($bin->data, $this->start, ($this->end - $this->start) & ~1);
        $this->w = array_values(unpack($be ? 'n*' : 'v*', $chunk) ?: []);
        $this->n = count($this->w);
    }

    /** @return array<int, array> raw candidates: layout, address, rows, cols, xAddr, yAddr, ... */
    public function scan(): array
    {
        $out = [];
        $w = $this->w;
        $i = 0;
        while ($i < $this->n - 4) {
            $hit = $this->tryLayoutB($i) ?? $this->tryLayoutA($i) ?? $this->tryCurve($i);
            if ($hit) {
                $hit = $this->preferLaterB($i, $hit);
            }
            if ($hit) {
                $out[] = $hit;
                $i = $hit['_endWord'];

                continue;
            }
            $i++;
        }

        return $out;
    }

    /**
     * A small hit can start inside the header of a real B map (its counts and first axis values
     * look like a short map). If a clearly larger B map starts within the hit's axes, take it.
     *
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function preferLaterB(int $i, array $hit): array
    {
        $axesEnd = ($hit['address'] - $this->start) / 2;
        for ($j = $i + 1; $j < $axesEnd; $j++) {
            $b = $this->tryLayoutB($j);
            if ($b && $b['rows'] >= 4 && $b['cols'] >= 4 && 2 * $hit['rows'] * $hit['cols'] <= $b['rows'] * $b['cols']) {
                return $b;
            }
        }

        return $hit;
    }

    /** A plain index 0..n-1 (or 1..n): never a calibration axis on its own. */
    private function isIndexAxis(int $at, int $count): bool
    {
        if ($count < 2 || $count > self::MAX_INDEX_AXIS || $at + $count > $this->n || $this->w[$at] > 1) {
            return false;
        }
        for ($k = 1; $k < $count; $k++) {
            if ($this->w[$at + $k] !== $this->w[$at] + $k) {
                return false;
            }
        }

        return true;
    }

    private function isAxis(int $at, int $count): bool
    {
        if ($count < 2 || $count > self::MAX_AXIS || $at + $count > $this->n) {
            return false;
        }
        $w = $this->w;
        if ($w[$at] === 0xFFFF) {
            return false;
        }
        // unsigned strictly increasing, or signed increasing starting at a small negative (e.g. -40 degC)
        $signed = $w[$at] >= 0xD8F0;
        $prev = $signed ? $w[$at] - 0x10000 : $w[$at];
        for ($k = 1; $k < $count; $k++) {
            $v = $w[$at + $k];
            if ($signed && $v >= 0x8000) {
                $v -= 0x10000;
            }
            if ($v <= $prev) {
                return false;
            }
            $prev = $v;
        }
        // reject trivial 0,1,2,3... index sequences and tiny counts of tiny numbers (common in code/tables)
        $span = $prev - ($signed ? $w[$at] - 0x10000 : $w[$at]);
        if ($span === $count - 1) {
            return false;
        }

        return true;
    }

    private function dataOk(int $at, int $size): bool
    {
        if ($at + $size > $this->n) {
            return false;
        }
        $ff = 0;
        for ($k = 0; $k < $size; $k++) {
            if ($this->w[$at + $k] === 0xFFFF) {
                $ff++;
            }
        }

        return $ff < max(2, $size / 4);
    }

    /** B: n m X Y Z */
    private function tryLayoutB(int $i): ?array
    {
        $n = $this->w[$i];
        $m = $this->w[$i + 1] ?? 0;
        if ($n < 2 || $m < 2 || $n > self::MAX_AXIS || $m > self::MAX_AXIS || $n * $m < 6) {
            return null;
        }
        $x = $i + 2;
        $y = $x + $n;
        $z = $y + $m;
        $xOk = $this->isAxis($x, $n);
        $yOk = $this->isAxis($y, $m);
        // one index axis is fine when the other is a real axis of some length (per-gear limiters)
        $xOk = $xOk || ($yOk && $m >= 6 && $this->isIndexAxis($x, $n));
        $yOk = $yOk || ($xOk && $n >= 6 && $this->isIndexAxis($y, $m));
        if (! $xOk || ! $yOk || ! $this->dataOk($z, $n * $m)) {
            return null;
        }

        // values are stored per point of the first axis, so that axis is the rows (as in layout A)
        return $this->make('B', $z, $n, $m, $y, $m, $x, $n, $i);
    }

    /** A: [id] n X [id] m Y Z */
    private function tryLayoutA(int $i): ?array
    {
        $n = $this->w[$i];
        if ($n < 2 || $n > self::MAX_AXIS || ! $this->isAxis($i + 1, $n)) {
            return null;
        }
        $j = $i + 1 + $n;
        foreach ([0, 1] as $skip) {
            $c = $j + $skip;
            $m = $this->w[$c] ?? 0;
            if ($m < 2 || $m > self::MAX_AXIS || $n * $m < 6 || ! $this->isAxis($c + 1, $m)) {
                continue;
            }
            $z = $c + 1 + $m;
            if (! $this->dataOk($z, $n * $m)) {
                continue;
            }
            // first axis is the outer (row) dimension, as in the DDE4 / EDC15 definitions
            $hit = $this->make('A', $z, $n, $m, $c + 1, $m, $i + 1, $n, $i);
            $hit['idWords'] = $skip === 1;

            return $hit;
        }

        return null;
    }

    /** C: n X Z (curves) */
    private function tryCurve(int $i): ?array
    {
        $n = $this->w[$i];
        if ($n < 4 || $n > self::MAX_AXIS || ! $this->isAxis($i + 1, $n) || ! $this->dataOk($i + 1 + $n, $n)) {
            return null;
        }
        // the count is repeated just before the real count (padding): the curve starts one word later
        if ($this->w[$i + 1] === $n && $this->tryCurve($i + 1) !== null) {
            return null;
        }
        // reject if "data" is just the axis continuing (part of a larger monotonic run)
        $z = $i + 1 + $n;
        if ($this->w[$z] > $this->w[$z - 1] && $this->isAxis($z - 1, min($n, $this->n - $z))) {
            return null;
        }

        return $this->make('C', $z, 1, $n, $i + 1, $n, null, 0, $i);
    }

    private function make(string $layout, int $z, int $rows, int $cols, int $x, int $nx, ?int $y, int $ny, int $headerWord): array
    {
        $addr = fn (int $word) => $this->start + $word * 2;
        $data = array_slice($this->w, $z, $rows * $cols);

        return [
            'layout' => $layout,
            'be' => $this->be,
            'header' => $addr($headerWord),
            'address' => $addr($z),
            'rows' => $rows,
            'cols' => $cols,
            'xAddr' => $addr($x),
            'xCount' => $nx,
            'xRaw' => array_slice($this->w, $x, $nx),
            'yAddr' => $y === null ? null : $addr($y),
            'yCount' => $ny,
            'yRaw' => $y === null ? [] : array_slice($this->w, $y, $ny),
            'xId' => $layout === 'A' && $headerWord > 0 ? null : null,
            'data' => $data,
            '_endWord' => $z + $rows * $cols,
        ];
    }
}
