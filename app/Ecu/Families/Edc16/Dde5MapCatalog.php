<?php

namespace App\Ecu\Families\Edc16;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Named tables of BMW EDC16C (DDE5) software, found by reverse engineering the code that reads them.
 *
 * Use sites (the lis/addi pair in the function given) are verified in the disassembly; what a table
 * means and its units are inferred from the code around it and the value ranges, so every
 * description says which function reads it.
 *
 * Table layout (big-endian): 2D [nx][ny][first axis nx][second axis ny][data, nx blocks of ny],
 * curve [n][axis n][values n]. The first axis becomes the rows.
 */
class Dde5MapCatalog
{
    private const RPM = ['Engine speed', '1/min', 1.0, 0.0, 0];

    private const QUANTITY = ['Injection quantity', 'mg/st', 0.01, 0.0, 1];

    private const AIR = ['Air mass', 'mg/st', 0.1, 0.0, 0];

    private const ATM = ['Atm. pressure', 'hPa', 1.0, 0.0, 0];

    private const PEDAL = ['Pedal', '%', 100 / 8192, 0.0, 0];

    private const TEMPERATURE = ['Temperature', '°C', 0.1, -273.2, 0];

    private const RAW = ['Input', '', 1.0, 0.0, 0];

    private const TORQUE = ['Nm', 0.1, 1];

    private const LAMBDA = ['λ', 0.001, 3];

    private const FACTOR = ['-', 1 / 8192, 3];

    private const BOOST = ['hPa', 1.0, 0];

    private const RAIL = ['bar', 0.1, 1];

    private const TIMING = ['°CrA', 0.0234375, 2];

    /**
     * SW number => CPU address of the table header => [name, category, value [unit, factor, decimals], first axis, second axis|null, read by].
     *
     * @var array<string, array<int, array{0: string, 1: string, 2: array{0: string, 1: float, 2: int}, 3: array, 4: ?array, 5: string}>>
     */
    private const TABLES = [
        '1037361878' => [
            0x8E01D4 => ["Driver's wish 1", 'Torque / IQ', self::TORQUE, self::RPM, self::PEDAL, 'pedal evaluation, use site 0x16020'],
            0x8E034C => ["Driver's wish 2", 'Torque / IQ', self::TORQUE, self::RPM, self::PEDAL, 'pedal evaluation, use site 0x16040'],
            0x8E8236 => ['Full-load torque limit A', 'Limiters', self::TORQUE, self::RPM, null, 'torque limitation 0x856184 (blended with B by a flag)'],
            0x8E82E2 => ['Full-load torque limit B', 'Limiters', self::TORQUE, self::RPM, null, 'torque limitation 0x856184 (blended with A by a flag)'],
            0x8E8362 => ['Torque limit, second limit A', 'Limiters', self::TORQUE, self::RPM, null, 'torque limitation 0x856184 (the result is the lower of the two limits)'],
            0x8E8338 => ['Torque limit, second limit B', 'Limiters', self::TORQUE, self::RPM, null, 'torque limitation 0x856184'],
            0x8E828C => ['Torque limit, protection mode', 'Limiters', self::TORQUE, self::RPM, null, 'torque limitation 0x856184, used while a protection condition is active'],
            0x8E806C => ['Torque limit factor (atm. pressure)', 'Corrections', self::FACTOR, self::ATM, self::RPM, 'torque limitation 0x856184 (8192 = 1.0)'],
            0x8E8136 => ['Torque limit factor (temperature)', 'Corrections', self::FACTOR, self::TEMPERATURE, self::RAW, 'torque limitation 0x856184 (8192 = 1.0)'],
            0x8E34DC => ['Torque limit chain 1', 'Limiters', self::TORQUE, self::RPM, null, 'torque limit chain 0x2030C (gearbox or protection limit)'],
            0x8E3488 => ['Torque limit chain 2', 'Limiters', self::TORQUE, self::RPM, null, 'torque limit chain 0x2030C (gearbox or protection limit)'],
            0x8E34B2 => ['Torque limit chain 3', 'Limiters', self::TORQUE, self::RPM, null, 'torque limit chain 0x2030C (gearbox or protection limit)'],
            0x8E984C => ['Smoke limiter, λ min A1', 'Limiters', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4: quantity = air mass × 690 / λ'],
            0x8E916C => ['Smoke limiter, λ min A2', 'Limiters', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4: quantity = air mass × 690 / λ'],
            0x8E9A90 => ['Smoke limiter, λ min B1', 'Limiters', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4: quantity = air mass × 690 / λ'],
            0x8E93B0 => ['Smoke limiter, λ min B2', 'Limiters', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4: quantity = air mass × 690 / λ'],
            0x8E8F28 => ['Smoke limiter, λ correction 1', 'Corrections', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4'],
            0x8E8CE4 => ['Smoke limiter, λ correction 2', 'Corrections', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4'],
            0x8E8AA0 => ['Smoke limiter, λ correction 3', 'Corrections', self::LAMBDA, self::RPM, self::AIR, 'smoke limitation 0x8551E4'],
            0x8F9196 => ['Boost pressure target 1', 'Boost', self::BOOST, self::RPM, self::QUANTITY, 'boost set point 0x86EEBC (absolute pressure)'],
            0x8F8D0E => ['Boost pressure target 2', 'Boost', self::BOOST, self::RPM, self::QUANTITY, 'boost set point 0x86EEBC (absolute pressure)'],
            0x8F8F52 => ['Boost pressure target 3', 'Boost', self::BOOST, self::RPM, self::QUANTITY, 'boost set point 0x86EEBC (absolute pressure)'],
            0x8F93DA => ['Boost pressure limit (atm. pressure)', 'Boost', self::BOOST, self::RPM, self::ATM, 'boost set point 0x86EEBC (absolute pressure)'],
            0x8FC9C6 => ['Rail pressure target', 'Rail', self::RAIL, self::RPM, self::QUANTITY, 'rail pressure set point 0x4D930'],
            0x8FC782 => ['Rail pressure target (start / low rpm)', 'Rail', self::RAIL, self::RPM, self::QUANTITY, 'rail pressure set point 0x4D930'],
            0x8FC444 => ['Rail pressure target 2', 'Rail', self::RAIL, self::RPM, self::QUANTITY, 'rail pressure function 0x4D854'],
            0x8ECCE8 => ['Injection timing (SOI) main', 'Injection', self::TIMING, self::RPM, self::QUANTITY, 'main injection timing 0x37334 (default mode)'],
            0x8ECF2C => ['Injection timing (SOI) main, mode 2', 'Injection', self::TIMING, self::RPM, self::QUANTITY, 'main injection timing 0x37334'],
            0x8ED170 => ['Injection timing (SOI) main, mode 3', 'Injection', self::TIMING, self::RPM, self::QUANTITY, 'main injection timing 0x37334'],
            0x8EEC1A => ['Injection timing (SOI) 2', 'Injection', self::TIMING, self::RPM, self::QUANTITY, 'injection timing 0x37DE4'],
        ],
    ];

    public static function supports(?string $software): bool
    {
        return $software !== null && isset(self::TABLES[$software]);
    }

    /**
     * Map definitions of the named tables whose header in the file matches the catalog's shape.
     *
     * @return MapDef[]
     */
    public static function maps(Bin $bin, ?string $software): array
    {
        $maps = [];
        foreach (array_keys(self::TABLES[$software] ?? []) as $cpu) {
            $map = self::map($bin, (string) $software, $cpu);
            if ($map !== null) {
                $maps[] = $map;
            }
        }

        return $maps;
    }

    /**
     * Map definition of one named table, read at $at (a file offset; the table's own place by default,
     * or a copy of it elsewhere).
     */
    public static function map(Bin $bin, string $software, int $cpu, ?int $at = null): ?MapDef
    {
        $entry = self::TABLES[$software][$cpu] ?? null;
        $header = $at ?? BmwEdc16cLayout::cpuToFile($cpu);
        if ($entry === null || $header === null) {
            return null;
        }
        [$name, $category, $value, $first, $second, $readBy] = $entry;
        $map = $second === null ? self::curve($bin, $header, $name, $category, $value, $first) : self::map2d($bin, $header, $name, $category, $value, $first, $second);
        if ($map !== null) {
            $map->description = sprintf('Read by %s. Use site verified in the code; meaning and units inferred from it and the value ranges.', $readBy);
        }

        return $map;
    }

    /**
     * @param  array{0: string, 1: float, 2: int}  $value
     * @param  array{0: string, 1: string, 2: float, 3: float, 4: int}  $axis
     */
    private static function curve(Bin $bin, int $header, string $name, string $category, array $value, array $axis): ?MapDef
    {
        $n = $bin->u16($header, true);
        if ($n < 2 || $n > 48 || ! self::ascending($bin, $header + 2, $n)) {
            return null;
        }
        $map = new MapDef($name, $header + 2 + 2 * $n, 1, $n, 's16', true, $value[1], 0, $value[0], $category, '', 'catalog', 1.0, false, $value[2]);
        $map->x = self::axis($axis, $n, $header + 2);

        return $map;
    }

    /**
     * @param  array{0: string, 1: float, 2: int}  $value
     * @param  array{0: string, 1: string, 2: float, 3: float, 4: int}  $first
     * @param  array{0: string, 1: string, 2: float, 3: float, 4: int}  $second
     */
    private static function map2d(Bin $bin, int $header, string $name, string $category, array $value, array $first, array $second): ?MapDef
    {
        $nx = $bin->u16($header, true);
        $ny = $bin->u16($header + 2, true);
        if ($nx < 2 || $ny < 2 || $nx > 48 || $ny > 48 || ! self::ascending($bin, $header + 4, $nx) || ! self::ascending($bin, $header + 4 + 2 * $nx, $ny)) {
            return null;
        }
        $data = $header + 4 + 2 * ($nx + $ny);
        $map = new MapDef($name, $data, $nx, $ny, 's16', true, $value[1], 0, $value[0], $category, '', 'catalog', 1.0, false, $value[2]);
        $map->y = self::axis($first, $nx, $header + 4);
        $map->x = self::axis($second, $ny, $header + 4 + 2 * $nx);

        return $map;
    }

    /**
     * @param  array{0: string, 1: string, 2: float, 3: float, 4: int}  $axis
     */
    private static function axis(array $axis, int $count, int $address): Axis
    {
        return new Axis($count, $address, 's16', true, $axis[2], $axis[3], $axis[1], $axis[0], null, $axis[4]);
    }

    private static function ascending(Bin $bin, int $at, int $count): bool
    {
        for ($i = 1; $i < $count; $i++) {
            if ($bin->s16($at + 2 * $i, true) <= $bin->s16($at + 2 * ($i - 1), true)) {
                return false;
            }
        }

        return true;
    }
}
