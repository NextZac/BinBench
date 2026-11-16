<?php

namespace App\Ecu\Patches;

use App\Ecu\Bin;

/**
 * Two maps on the cruise control I/O switch: RAM 0xD15C (cruise on) selects Map 2.
 *
 * Six stubs of 0x20 bytes at 0xBE000: `mov Rn,[slot]` (stock table), and when 0xD15C is set,
 * `mov Rn,#copy`. Bytes are from the read of the E46 330d after it was flashed and driven (Oct 2026).
 */
class Dde4CruiseSwitchMultimap extends Dde4Multimap
{
    public function id(): string
    {
        return 'dde4-multimap-2';
    }

    public function describe(): array
    {
        return [
            'name' => 'Multimap: 2 maps on the cruise I/O switch',
            'summary' => 'Cruise control switched on (I/O) selects Map 2, switched off runs Map 1, the file’s normal tables.',
            'details' => [
                'Switches the boost actuator duty limit, the launch, raised and normal torque limiters, and two main injection timing tables.',
                'Map 2 starts as a copy of Map 1, so nothing changes until you edit the “Map 2” tables (Multimap category).',
                'The two timing tables share their axes with Map 1 and the other timing maps: editing an axis changes all of them.',
            ],
            'software' => self::SOFTWARE,
            'tested' => 'Flashed and confirmed working on an E46 330d (1037351632), Oct 2026.',
            'flash' => 'Changes program code: write the full flash, not a calibration-only write. Save with checksum correction on.',
        ];
    }

    protected function code(): string
    {
        $stub = fn (string $load, string $copy, int $reg): string => $load.'ecf4f2f45cd12d02e6f'.dechex($reg).$copy.'fcf4db00';
        $pad = str_repeat('c3', 12);

        return implode($pad, [
            $stub('f2fce813', '000a', 12),  // 0xBE000 VNT duty limit    -> 0x70A00
            $stub('f2f38c10', 'a00d', 3),   // 0xBE020 SOI earliest      -> 0x70DA0
            $stub('f2f37c10', '00bd', 3),   // 0xBE040 SOI without pilot -> 0x7BD00
            $stub('f2fcd612', 'c00a', 12),  // 0xBE060 launch TL         -> 0x70AC0
            $stub('f2fccc12', '100b', 12),  // 0xBE080 raised TL         -> 0x70B10
            $stub('f2fcce12', 'a00e', 12),  // 0xBE0A0 normal TL         -> 0x70EA0
        ]);
    }

    protected function hooks(): array
    {
        return [
            [0x18E1E, 'f2f38c10', 'da0b20e0'],
            [0x18FEA, 'f2f38c10', 'da0b20e0'],
            [0x18E9E, 'f2f37c10', 'da0b40e0'],
            [0x27CC0, 'f2fce813', 'da0b00e0'],
            [0x29238, 'f2fcd612', 'da0b60e0'],
            [0x2938C, 'f2fccc12', 'da0b80e0'],
            [0x2941C, 'f2fccc12', 'da0b80e0'],
            [0x293D4, 'f2fcce12', 'da0ba0e0'],
            [0x29442, 'f2fcce12', 'da0ba0e0'],
        ];
    }

    protected function extraTables(Bin $bin): array
    {
        return [];
    }

    protected function extraAreas(): array
    {
        return [];
    }

    protected function extraMaps(Bin $bin): array
    {
        return [];
    }

    protected function map1Note(): string
    {
        return 'Used while cruise control is off.';
    }

    protected function map2Note(): string
    {
        return 'Used while cruise control is switched on (I/O).';
    }
}
