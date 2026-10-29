<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Encodes physical values back into a map, rounding and clamping to the storage type
 * exactly like the editor does.
 */
class MapWriter
{
    private const RANGES = [
        'u8' => [0, 0xFF],
        's8' => [-0x80, 0x7F],
        'u16' => [0, 0xFFFF],
        's16' => [-0x8000, 0x7FFF],
        'u32' => [0, 0xFFFFFFFF],
        's32' => [-0x80000000, 0x7FFFFFFF],
    ];

    /**
     * @param  float[][]  $grid  physical values, rows x cols
     */
    public static function write(Bin $bin, MapDef $map, array $grid): Bin
    {
        $data = $bin->data;
        $size = Bin::typeSize($map->dtype);
        for ($r = 0; $r < $map->rows; $r++) {
            for ($c = 0; $c < $map->cols; $c++) {
                $index = $map->colMajor ? $c * $map->rows + $r : $r * $map->cols + $c;
                $bytes = self::encode(($grid[$r][$c] - $map->offset) / ($map->factor ?: 1), $map->dtype, $map->be);
                $at = $map->address + $index * $size;
                for ($i = 0; $i < $size; $i++) {
                    $data[$at + $i] = $bytes[$i];
                }
            }
        }

        return new Bin($data);
    }

    public static function encode(float $raw, string $dtype, bool $be): string
    {
        if ($dtype === 'f32') {
            return pack($be ? 'G' : 'g', $raw);
        }
        $value = (int) self::storable($raw, $dtype);

        return match ($dtype) {
            'u8', 's8' => chr($value & 0xFF),
            'u16', 's16' => pack($be ? 'n' : 'v', $value & 0xFFFF),
            default => pack($be ? 'N' : 'V', $value & 0xFFFFFFFF),
        };
    }

    /** The raw value the storage type actually holds after rounding and clamping. */
    public static function storable(float $raw, string $dtype): float
    {
        if ($dtype === 'f32') {
            return $raw;
        }
        [$min, $max] = self::RANGES[$dtype];

        return max($min, min($max, round($raw)));
    }
}
