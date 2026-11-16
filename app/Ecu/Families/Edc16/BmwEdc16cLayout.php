<?php

namespace App\Ecu\Families\Edc16;

use App\Ecu\Bin;

/**
 * Full read of a BMW EDC16C (DDE5, MPC555): 448 KB internal flash followed by 1 MB external flash,
 * 1,507,328 bytes.
 *
 *   file 0x000000-0x06FFFF = CPU 0x000000-0x06FFFF  startup block + internal application
 *   file 0x070000-0x16FFFF = CPU 0x800000-0x8FFFFF  external flash (boot, loader, application, data)
 *
 * Calibration is CPU 0x8E0000-0x8FFBFF (file 0x150000-0x16FBFF). Code addresses every table with an
 * absolute "lis rX,hi / addi rX,rX,lo" pair; there are no pointer tables.
 * Worked out on SW 1037361878 (X5 E53 3.0d).
 */
class BmwEdc16cLayout
{
    public const SIZE = 0x170000;

    private const EXTERNAL_FILE = 0x70000;

    private const EXTERNAL_CPU = 0x800000;

    public const CALIBRATION_CPU = [0x8E0000, 0x8FFC00];

    /** Code areas (file offsets): internal application, external application. */
    private const CODE = [[0x8000, 0x70000], [0xB0000, 0xF0000]];

    /** How far after a "lis" the matching "addi" may follow. */
    private const PAIR_WINDOW = 8;

    public static function matches(Bin $bin): bool
    {
        return $bin->size === self::SIZE
            && (str_contains($bin->data, 'EDC16C/C (BMW) MPC555') || str_contains($bin->data, 'BMW EDC16C'));
    }

    public static function cpuToFile(int $cpu): ?int
    {
        if ($cpu >= 0 && $cpu < self::EXTERNAL_FILE) {
            return $cpu;
        }
        if ($cpu >= self::EXTERNAL_CPU && $cpu < self::EXTERNAL_CPU + self::SIZE - self::EXTERNAL_FILE) {
            return $cpu - self::EXTERNAL_CPU + self::EXTERNAL_FILE;
        }

        return null;
    }

    public static function fileToCpu(int $file): int
    {
        return $file < self::EXTERNAL_FILE ? $file : $file - self::EXTERNAL_FILE + self::EXTERNAL_CPU;
    }

    /**
     * Calibration range as file offsets [start, end).
     *
     * @return array{0: int, 1: int}
     */
    public static function calibrationRange(): array
    {
        return [(int) self::cpuToFile(self::CALIBRATION_CPU[0]), (int) self::cpuToFile(self::CALIBRATION_CPU[1] - 1) + 1];
    }

    /**
     * File offsets of every calibration address the code builds with a lis/addi pair (table starts,
     * plus single values that are loaded the same way).
     *
     * @return array<int, true>
     */
    public static function referencedAddresses(Bin $bin): array
    {
        $out = [];
        foreach (self::CODE as [$start, $end]) {
            $words = array_values(unpack('N*', substr($bin->data, $start, $end - $start)) ?: []);
            $count = count($words);
            foreach ($words as $i => $w) {
                // lis rD,hi  =  addis rD,0,hi
                if (($w >> 26) !== 15 || (($w >> 16) & 31) !== 0) {
                    continue;
                }
                $register = ($w >> 21) & 31;
                for ($k = 1; $k <= self::PAIR_WINDOW && $i + $k < $count; $k++) {
                    $next = $words[$i + $k];
                    // addi rD,rD,lo
                    if (($next >> 26) === 14 && (($next >> 21) & 31) === $register && (($next >> 16) & 31) === $register) {
                        $low = $next & 0xFFFF;
                        $cpu = ((($w & 0xFFFF) << 16) + ($low >= 0x8000 ? $low - 0x10000 : $low)) & 0xFFFFFFFF;
                        if ($cpu >= self::CALIBRATION_CPU[0] && $cpu < self::CALIBRATION_CPU[1]) {
                            $out[(int) self::cpuToFile($cpu)] = true;
                        }
                        break;
                    }
                }
            }
        }

        return $out;
    }
}
