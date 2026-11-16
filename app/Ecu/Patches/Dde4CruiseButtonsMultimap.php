<?php

namespace App\Ecu\Patches;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Three maps on the cruise control buttons (with cruise I/O off): + steps Map 1 → 2 → 3 → 1, − goes back
 * to Map 1, power-up is Map 1. The map number is shown on the rev counter (CAN 0x316) as 1000/2000/3000 rpm
 * for 1.5 s after each +, − or Resume press.
 *
 * State in RAM 0xD1B4 (bits 0-1 map, 2-4 previous buttons), tacho countdown 0xD1B5 in 20 ms ticks.
 * Hooks: FGR init 0x247FA, button decode 0x24CA6, CAN 0x316 hand-off 0x1BDAA, the table loads, and the
 * only other write to 0xD1B4 (0x252F8, cruise resume timestamp) is NOPed.
 *
 * Bytes are from the v2 build (E46 3-map with the 1.5 s tacho fix); the same code was flashed to the
 * 530d without the tacho fix and its map switching confirmed.
 */
class Dde4CruiseButtonsMultimap extends Dde4Multimap
{
    private const MAP3_LAUNCH = 0x70EF0;

    private const MAP3_SMOKE = 0x7BF00;

    private const SMOKE_STATIC = 0x7AAD6;

    private const SMOKE_DYNAMIC = 0x7A2DE;

    /** Air mass breakpoints of the Map 3 smoke table (0.1 mg/stroke). */
    private const SMOKE_AIR = [2500, 3500, 4500, 5500, 6500, 7500, 8500, 10000];

    /** Rpm breakpoints after the first one, which is the stock table's first breakpoint at or above 700. */
    private const SMOKE_RPM = [1000, 1300, 1600, 2200, 2800, 4000, 4900];

    public function id(): string
    {
        return 'dde4-multimap-3';
    }

    public function describe(): array
    {
        return [
            'name' => 'Multimap: 3 maps on the cruise +/− buttons',
            'summary' => 'With cruise control off, + steps Map 1 → 2 → 3 → 1 and − goes back to Map 1. Power-up is always Map 1. The rev counter shows 1000/2000/3000 rpm for 1.5 s after each press to confirm the map.',
            'details' => [
                'Map 2 switches the boost actuator duty limit, the launch, raised and normal torque limiters, and two main injection timing tables.',
                'Map 3 has its own launch torque limiter and one 8×8 smoke limiter that replaces both the static and the dynamic smoke limiter. Its other tables are Map 1’s.',
                'Map 2 starts as a copy of Map 1. The Map 3 smoke table starts from the lower of the two stock smoke limiters at its breakpoints, so Map 3 is close to Map 1 until you edit it.',
                'The two timing tables share their axes with Map 1 and the other timing maps: editing an axis changes all of them.',
                'The cruise Resume timestamp write at 0x252F8 is removed to free the state byte. On cars with a speed signal, Resume may not behave as stock.',
            ],
            'software' => self::SOFTWARE,
            'tested' => 'Map switching confirmed on a 530d (1037351761). This build, with the 1.5 s rev counter fix, has not been run on a car yet.',
            'flash' => 'Changes program code: write the full flash, not a calibration-only write. Save with checksum correction on.',
        ];
    }

    protected function code(): string
    {
        return 'f68e5cd1f68eb4d1db00ecf5ecf6ecf2c2f5b4d1f04568434842fd02e005e004e006f2f2d6c466f212002d012ff6f2f2d6c466f240002d013ff6f2f2d6c466f209002d014ff691506056f2f25cd13d11f0252d0fe6f24b00f7f4b5d1f02568242d04084148438d01e00466f508002d01e0047064f7fcb4d1fcf2fcf6fcf5f2f4d6c4db00'
            .'c2fcb4d168c348c12d03f2fce813db00e6fc000adb00'      // VNT duty limit: Map 2 -> 0x70A00
            .'c2f3b4d1683348312d03f2f38c10db00e6f3a00ddb00'      // SOI earliest: Map 2 -> 0x70DA0
            .'c2f3b4d1683348312d03f2f37c10db00e6f300bddb00'      // SOI without pilot: Map 2 -> 0x7BD00
            .'c2fcb4d168c348c12d03f2fccc12db00e6fc100bdb00'      // raised TL: Map 2 -> 0x70B10
            .'c2fcb4d168c348c12d03f2fcce12db00e6fca00edb00'      // normal TL: Map 2 -> 0x70EA0
            .'c2fcb4d168c348c12d0548c22d06f2fcd612db00e6fcc00adb00e6fcf00edb00'  // launch TL: Map 2 -> 0x70AC0, Map 3 -> 0x70EF0
            .'c2fcb4d168c348c22d03f2fcea12db00e6fc00bfdb00'      // dynamic smoke: Map 3 -> 0x7BF00
            .'c2fcb4d168c348c22d03f2fcda12db00e6fc00bfdb00'      // static smoke: Map 3 -> 0x7BF00
            .'c2f0b5d12d152801f7f0b5d1f2f15cd13d0fc2f1b4d16813e6f0001948118d06e6f0003248112d02e6f0004bf6f02cc2f2f00814db00'; // rev counter
    }

    protected function hooks(): array
    {
        return [
            [0x247FA, 'f68e5cd1', 'da0b00e0'],
            [0x24CA6, 'f2f4d6c4', 'da0b0ae0'],
            [0x252F8, 'f6f3b4d1', 'cc00cc00'],
            [0x1BDAA, 'f2f00814', 'da0b3ee1'],
            [0x27CC0, 'f2fce813', 'da0b84e0'],
            [0x18E1E, 'f2f38c10', 'da0b9ae0'],
            [0x18FEA, 'f2f38c10', 'da0b9ae0'],
            [0x18E9E, 'f2f37c10', 'da0bb0e0'],
            [0x2938C, 'f2fccc12', 'da0bc6e0'],
            [0x2941C, 'f2fccc12', 'da0bc6e0'],
            [0x293D4, 'f2fcce12', 'da0bdce0'],
            [0x29442, 'f2fcce12', 'da0bdce0'],
            [0x29238, 'f2fcd612', 'da0bf2e0'],
            [0x28E40, 'f2fcea12', 'da0b12e1'],
            [0x28B2E, 'f2fcda12', 'da0b28e1'],
            [0x28E5E, 'f2fcda12', 'da0b28e1'],
        ];
    }

    protected function extraSlots(): array
    {
        return [0x12DA => self::SMOKE_STATIC, 0x12EA => self::SMOKE_DYNAMIC];
    }

    protected function extraAreas(): array
    {
        return [[self::MAP3_LAUNCH, self::TABLES['adb']['length']], [self::MAP3_SMOKE, 0xA8]];
    }

    protected function extraTables(Bin $bin): array
    {
        $launch = self::TABLES['adb'];

        return [
            [self::MAP3_LAUNCH, $bin->slice($launch['address'], $launch['length'])],
            [self::MAP3_SMOKE, $this->smokeTable($bin)],
        ];
    }

    protected function extraMaps(Bin $bin): array
    {
        $launch = $this->tableMap('adb', self::MAP3_LAUNCH, 'Map 3', 'Used in Map 3 only. The main limit on how much fuel Map 3 delivers.');

        $smoke = new MapDef('Map 3 · Smoke limiter 8×8 (replaces mrwBRA_KF + mrwBRDY_KF)', self::MAP3_SMOKE + 0x28, 8, 8, 'u16', false, 0.01, 0, 'mg/st', 'Multimap',
            'Used in Map 3 only, for both the static and the dynamic smoke limit. Rows rpm, columns air mass.', 'patch', 1.0);
        $smoke->y = $this->rpmAxis(8, self::MAP3_SMOKE + 0x04);
        $smoke->x = new Axis(8, self::MAP3_SMOKE + 0x18, 'u16', false, 0.1, 0, 'mg/st', 'Air mass', null, 0);

        return [$launch, $smoke];
    }

    protected function map1Note(): string
    {
        return 'Used in Map 1 (and Map 3 unless Map 3 has its own copy).';
    }

    protected function map2Note(): string
    {
        return 'Used in Map 2 only.';
    }

    /**
     * Map 3 smoke table: [x var][8][rpm][y var][8][air][8×8 limits by rpm], 168 bytes. Each limit is the
     * lower of the static and the dynamic stock limiter at that point.
     */
    private function smokeTable(Bin $bin): string
    {
        $static = $this->readTable($bin, self::SMOKE_STATIC);
        $dynamic = $this->readTable($bin, self::SMOKE_DYNAMIC);

        $first = array_values(array_filter($static['x'], fn (int $rpm): bool => $rpm >= 700))[0] ?? 750;
        $rpms = [min($first, self::SMOKE_RPM[0] - 1), ...self::SMOKE_RPM];

        $limits = [];
        foreach ($rpms as $rpm) {
            foreach (self::SMOKE_AIR as $air) {
                $limits[] = (int) round(min($this->sample($static, $rpm, $air), $this->sample($dynamic, $rpm, $air)));
            }
        }

        return pack('v2', $static['xVar'], 8).pack('v*', ...$rpms)
            .pack('v2', $static['yVar'], 8).pack('v*', ...self::SMOKE_AIR)
            .pack('v*', ...$limits);
    }

    /**
     * @return array{xVar: int, yVar: int, x: int[], y: int[], z: int[][]}
     */
    private function readTable(Bin $bin, int $address): array
    {
        $nx = $bin->u16($address + 2);
        $yAt = $address + 4 + 2 * $nx;
        $ny = $bin->u16($yAt + 2);
        $read = fn (int $at, int $n): array => array_values(unpack('v'.$n, $bin->slice($at, 2 * $n)));
        $data = $read($yAt + 4 + 2 * $ny, $nx * $ny);

        return [
            'xVar' => $bin->u16($address),
            'yVar' => $bin->u16($yAt),
            'x' => $read($address + 4, $nx),
            'y' => $read($yAt + 4, $ny),
            'z' => array_chunk($data, $ny),
        ];
    }

    /**
     * Bilinear value at ($x, $y), held at the edges.
     *
     * @param  array{x: int[], y: int[], z: int[][]}  $table
     */
    private function sample(array $table, int $x, int $y): float
    {
        [$i, $fx] = $this->locate($table['x'], $x);
        [$j, $fy] = $this->locate($table['y'], $y);
        $z = $table['z'];
        $low = $z[$i][$j] + ($z[$i][$j + 1] - $z[$i][$j]) * $fy;
        $high = $z[$i + 1][$j] + ($z[$i + 1][$j + 1] - $z[$i + 1][$j]) * $fy;

        return $low + ($high - $low) * $fx;
    }

    /**
     * Lower breakpoint index and fraction towards the next one.
     *
     * @param  int[]  $axis
     * @return array{0: int, 1: float}
     */
    private function locate(array $axis, int $value): array
    {
        $last = count($axis) - 2;
        $i = 0;
        while ($i < $last && $value >= $axis[$i + 1]) {
            $i++;
        }
        $span = $axis[$i + 1] - $axis[$i];

        return [$i, $span > 0 ? max(0.0, min(1.0, ($value - $axis[$i]) / $span)) : 0.0];
    }
}
