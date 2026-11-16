<?php

namespace Tests\Support;

/**
 * A stock-looking DDE4 / EDC15C4 512 KB image with just what the multimap patches check: free C3 space,
 * the stock instructions at every hook site, the table pointer slots and the switched tables.
 */
class SyntheticDde4
{
    /** Stock instructions at the hook sites, [file offset, hex]. */
    public const HOOKS = [
        [0x18E1E, 'f2f38c10'], [0x18FEA, 'f2f38c10'], [0x18E9E, 'f2f37c10'], [0x1BDAA, 'f2f00814'],
        [0x247FA, 'f68e5cd1'], [0x24CA6, 'f2f4d6c4'], [0x252F8, 'f6f3b4d1'], [0x27CC0, 'f2fce813'],
        [0x28B2E, 'f2fcda12'], [0x28E40, 'f2fcea12'], [0x28E5E, 'f2fcda12'], [0x29238, 'f2fcd612'],
        [0x2938C, 'f2fccc12'], [0x2941C, 'f2fccc12'], [0x293D4, 'f2fcce12'], [0x29442, 'f2fcce12'],
    ];

    public const SMOKE_RPM = [550, 650, 750, 1000, 1300, 1600, 1900, 2200, 2500, 2800, 3100, 3400, 4000, 4300, 4600, 4900];

    public const SMOKE_AIR = [2500, 3000, 3500, 4000, 4500, 5000, 5500, 6000, 6500, 7000, 7500, 8000, 8500, 9000, 9500, 10000];

    public static function build(string $software = '1037351632', int $staticSmoke = 4000, int $dynamicSmoke = 3000): SyntheticBin
    {
        $bin = new SyntheticBin(0x80000, "\xC3");
        $bin->ascii(0x7FEF0, $software);
        foreach (self::HOOKS as [$address, $hex]) {
            $bin->ascii($address, (string) hex2bin($hex));
        }

        // pointer slots at 0x70000 + slot -> table (16-bit, relative to 0x70000)
        $slots = [0x13E8 => 0x73F4E, 0x12D6 => 0x7A8BA, 0x12CC => 0x7A910, 0x12CE => 0x7A960, 0x108C => 0x77D9E, 0x107C => 0x7809E, 0x12DA => 0x7AAD6, 0x12EA => 0x7A2DE];
        foreach ($slots as $slot => $table) {
            $bin->words(0x70000 + $slot, [$table - 0x70000]);
        }

        // VNT duty limit: [var][16][rpm][var][4][quantity][16x4]
        $bin->words(0x73F4E, [0xC016, 16, ...range(500, 4250, 250), 0xC062, 4, 0, 2000, 4000, 6000, ...array_fill(0, 64, 8000)]);
        // torque limiters: [var][19][rpm][19 values], distinct per table
        foreach ([0x7A8BA => 5000, 0x7A910 => 6000, 0x7A960 => 7000] as $address => $limit) {
            $bin->words($address, [0xC016, 19, ...range(500, 5000, 250), ...array_fill(0, 19, $limit)]);
        }
        // timing tables (data only, shared axes elsewhere)
        $bin->words(0x77D9E, array_map(fn (int $i): int => 400 + $i, range(0, 127)));
        $bin->words(0x7809E, array_map(fn (int $i): int => 300 + $i, range(0, 255)));
        // smoke limiters: [var][16][rpm][var][16][air][16x16]
        foreach ([0x7AAD6 => $staticSmoke, 0x7A2DE => $dynamicSmoke] as $address => $limit) {
            $bin->words($address, [0xC016, 16, ...self::SMOKE_RPM, 0xC20C, 16, ...self::SMOKE_AIR, ...array_fill(0, 256, $limit)]);
        }

        return $bin;
    }
}
