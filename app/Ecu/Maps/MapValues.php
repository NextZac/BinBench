<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Physical values of one map read from a dump, with bilinear lookup along its axes.
 * X runs along columns, Y along rows (see MapDef).
 */
class MapValues
{
    /** @var float[] */
    public readonly array $x;

    /** @var float[] */
    public readonly array $y;

    /** @var float[][] rows x cols */
    public readonly array $grid;

    public function __construct(public readonly MapDef $map, Bin $bin)
    {
        if ($map->address < 0 || $map->address + $map->byteSize() > $bin->size) {
            throw new \RangeException("Map {$map->name} lies outside the file.");
        }

        $size = Bin::typeSize($map->dtype);
        $grid = [];
        for ($r = 0; $r < $map->rows; $r++) {
            for ($c = 0; $c < $map->cols; $c++) {
                $index = $map->colMajor ? $c * $map->rows + $r : $r * $map->cols + $c;
                $grid[$r][$c] = $bin->read($map->address + $index * $size, $map->dtype, $map->be) * $map->factor + $map->offset;
            }
        }

        $this->grid = $grid;
        $this->x = self::axisValues($map->x, $map->cols, $bin);
        $this->y = self::axisValues($map->y, $map->rows, $bin);
    }

    /**
     * @return float[]
     */
    public static function axisValues(?Axis $axis, int $count, Bin $bin): array
    {
        if ($axis === null) {
            return range(0, max(0, $count - 1));
        }
        if ($axis->values !== null) {
            return array_map('floatval', array_slice($axis->values, 0, $count));
        }
        $size = Bin::typeSize($axis->dtype);
        if ($axis->address === null || $axis->address + $count * $size > $bin->size) {
            return range(0, max(0, $count - 1));
        }

        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = $bin->read($axis->address + $i * $size, $axis->dtype, $axis->be) * $axis->factor + $axis->offset;
        }

        return $values;
    }

    /**
     * Bilinear interpolation, clamped to the axis ends like the ECU does.
     */
    public function at(float $x, float $y = 0.0): float
    {
        [$c0, $c1, $tc] = self::bracket($this->x, $x);
        [$r0, $r1, $tr] = self::bracket($this->y, $y);

        $top = $this->grid[$r0][$c0] + ($this->grid[$r0][$c1] - $this->grid[$r0][$c0]) * $tc;
        $bottom = $this->grid[$r1][$c0] + ($this->grid[$r1][$c1] - $this->grid[$r1][$c0]) * $tc;

        return $top + ($bottom - $top) * $tr;
    }

    /**
     * Evaluates the map at an engine speed and a value on its other axis.
     */
    public function atRpm(float $rpm, float $other = 0.0): float
    {
        if ($this->map->rows === 1) {
            return $this->at($rpm);
        }
        if ($this->map->cols === 1) {
            return $this->at(0.0, $rpm);
        }

        return $this->rpmOnX() ? $this->at($rpm, $other) : $this->at($other, $rpm);
    }

    /**
     * @return float[]
     */
    public function rpmAxis(): array
    {
        return $this->map->rows === 1 || $this->rpmOnX() ? $this->x : $this->y;
    }

    /**
     * Values of the non-rpm axis of a map.
     *
     * @return float[]
     */
    public function otherAxis(): array
    {
        return $this->rpmOnX() ? $this->y : $this->x;
    }

    public function otherAxisDef(): ?Axis
    {
        return $this->rpmOnX() ? $this->map->y : $this->map->x;
    }

    /**
     * Which axis is engine speed: by axis name/unit first, then by its values; Bosch dumps put
     * engine speed on the rows when nothing says otherwise.
     */
    public function rpmOnX(): bool
    {
        $xNamed = self::isRpmAxis($this->map->x);
        $yNamed = self::isRpmAxis($this->map->y);
        if ($xNamed !== $yNamed) {
            return $xNamed;
        }
        if (! $xNamed && $this->map->rows > 1 && $this->map->cols > 1) {
            return self::looksLikeRpm($this->x) && ! self::looksLikeRpm($this->y);
        }

        return false;
    }

    /** True when an axis is, or looks like, engine speed. Curves only have the X axis to offer. */
    public function hasRpmAxis(): bool
    {
        if ($this->map->rows === 1) {
            return self::isRpmAxis($this->map->x) || self::looksLikeRpm($this->x);
        }
        if ($this->map->cols === 1) {
            return self::isRpmAxis($this->map->y) || self::looksLikeRpm($this->y);
        }

        return self::isRpmAxis($this->map->x) || self::isRpmAxis($this->map->y)
            || self::looksLikeRpm($this->x) || self::looksLikeRpm($this->y);
    }

    /**
     * Engine speed breakpoints: a few ascending values from idle-ish up to a diesel/petrol rev range.
     *
     * @param  float[]  $values
     */
    public static function looksLikeRpm(array $values): bool
    {
        return count($values) >= 3 && min($values) >= 0 && min($values) <= 2000
            && max($values) >= 2500 && max($values) <= 9000;
    }

    public static function isRpmAxis(?Axis $axis): bool
    {
        return $axis !== null && preg_match('/rpm|1\/min|engine speed|nmot|drehzahl/i', $axis->unit.' '.$axis->name) === 1;
    }

    /** True when the axis is strictly increasing, which every usable lookup axis is. */
    public static function isAscending(array $values): bool
    {
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            if ($values[$i] <= $values[$i - 1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  float[]  $axis
     * @return array{0: int, 1: int, 2: float} lower index, upper index, fraction
     */
    private static function bracket(array $axis, float $v): array
    {
        $n = count($axis);
        if ($n < 2 || $v <= $axis[0]) {
            return [0, 0, 0.0];
        }
        if ($v >= $axis[$n - 1]) {
            return [$n - 1, $n - 1, 0.0];
        }
        for ($i = 1; $i < $n; $i++) {
            if ($v <= $axis[$i]) {
                $span = $axis[$i] - $axis[$i - 1];

                return [$i - 1, $i, $span > 0 ? ($v - $axis[$i - 1]) / $span : 0.0];
            }
        }

        return [$n - 1, $n - 1, 0.0];
    }
}
