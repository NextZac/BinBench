<?php

namespace Tests\Support;

use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Builds small fake dumps containing inline-axis maps for scanner and API tests.
 */
class SyntheticBin
{
    private string $data;

    public function __construct(int $size = 0x80000, string $fill = "\xFF")
    {
        $this->data = str_repeat($fill, $size);
    }

    /**
     * @param  int[]  $words
     */
    public function words(int $at, array $words, bool $be = false): self
    {
        $bytes = pack($be ? 'n*' : 'v*', ...array_map(fn (int $w): int => $w & 0xFFFF, $words));
        $this->data = substr_replace($this->data, $bytes, $at, strlen($bytes));

        return $this;
    }

    public function ascii(int $at, string $text): self
    {
        $this->data = substr_replace($this->data, $text, $at, strlen($text));

        return $this;
    }

    /**
     * EDC15-style map: n, X[n], m, Y[m], Z[n*m]. Returns the data address.
     *
     * @param  int[]  $rowAxis
     * @param  int[]  $colAxis
     * @param  callable(int, int): int  $value
     */
    public function layoutA(int $at, array $rowAxis, array $colAxis, callable $value): int
    {
        $data = [];
        foreach (array_keys($rowAxis) as $r) {
            foreach (array_keys($colAxis) as $c) {
                $data[] = $value($r, $c);
            }
        }
        $this->words($at, [count($rowAxis), ...$rowAxis, count($colAxis), ...$colAxis, ...$data]);

        return $at + (2 + count($rowAxis) + count($colAxis)) * 2;
    }

    /**
     * Writes X axis, optional Y axis and data back to back and returns the matching definition.
     *
     * @param  int[]  $x
     * @param  ?int[]  $y
     * @param  int[][]  $rows
     */
    public function map(string $id, int $at, string $name, array $x, string $xName, string $xUnit, ?array $y, array $rows,
        float $factor = 0.01, string $unit = 'mg/st', ?float $xFactor = null): MapDef
    {
        $xFactor ??= $xUnit === '%' ? 0.01 : 1.0;
        $yAt = $at + count($x) * 2;
        $dataAt = $yAt + count($y ?? []) * 2;
        $this->words($at, $x)->words($yAt, $y ?? [])->words($dataAt, array_merge(...$rows));

        $map = new MapDef($name, $dataAt, count($rows), count($x), factor: $factor, unit: $unit, confidence: 0.8, id: $id);
        $map->x = new Axis(count($x), $at, factor: $xFactor, unit: $xUnit, name: $xName);
        $map->y = $y ? new Axis(count($y), $yAt, unit: 'rpm', name: 'Engine speed') : null;

        return $map;
    }

    public function data(): string
    {
        return $this->data;
    }
}
