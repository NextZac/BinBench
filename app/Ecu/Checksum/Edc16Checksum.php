<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;
use RuntimeException;

/**
 * Bosch EDC16U (VAG EDC16U1 / U31 / U34, MPC5xx, big-endian, 1-2 MB) checksums. VERIFY ONLY.
 *
 * Scheme as documented by ZedSuite (src/lib/ecu/bosch/checksums/edc16-checksum.ts, which
 * reports byte-exact results on 6 VAG before/after pairs); re-implemented here, NOT verified
 * against real files in this project (no EDC16 samples available):
 *   - each protected region carries the 8-byte marker FA DE CA FE CA FE AF FE at start+0x3C;
 *   - the two BE dwords before the marker are the region's start and inclusive end address
 *     (ECU memory address; the base 0x0 / 0x800000 follows from the marker position);
 *   - the sum of all BE dwords of the region, its last dword included, must be 0xD01FE500.
 * Other EDC16 variants (EDC16C BMW/PSA/Opel/...) use different schemes and are not claimed:
 * supports() needs a region that already sums correctly, so an unknown scheme is rejected.
 */
class Edc16Checksum implements ChecksumModule
{
    private const MAGIC = "\xFA\xDE\xCA\xFE\xCA\xFE\xAF\xFE";

    private const MAGIC_OFFSET = 0x3C;

    private const TARGET = 0xD01FE500;

    public function name(): string
    {
        return 'Bosch EDC16U (verify only)';
    }

    public function supports(Bin $bin): bool
    {
        if (! in_array($bin->size, [0x80000, 0x100000, 0x200000], true)) {
            return false;
        }
        foreach ($this->regions($bin->data) as [$s, $e]) {
            if ($this->sum($bin->data, $s, $e) === self::TARGET) {
                return true;
            }
        }

        return false;
    }

    public function check(Bin $bin): array
    {
        $out = [];
        foreach ($this->regions($bin->data) as $i => [$s, $e]) {
            $sum = $this->sum($bin->data, $s, $e);
            $stored = unpack('N', $bin->data, $e - 3)[1];
            $out[] = [
                'name' => sprintf('Region %d (0x%X-0x%X)', $i + 1, $s, $e),
                'start' => $s,
                'end' => $e,
                'stored' => sprintf('%08X', $stored),
                'calculated' => sprintf('%08X', ($stored + self::TARGET - $sum) & 0xFFFFFFFF),
                'ok' => $sum === self::TARGET,
            ];
        }

        return $out;
    }

    public function canFix(): bool
    {
        return false;
    }

    public function fix(Bin $bin): Bin
    {
        throw new RuntimeException('EDC16 checksum correction is not enabled (algorithm not verified on real files).');
    }

    /**
     * Protected regions as [start, end inclusive] file offsets.
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function regions(string $d): array
    {
        $size = strlen($d);
        $out = [];
        for ($p = strpos($d, self::MAGIC, 8); $p !== false; $p = strpos($d, self::MAGIC, $p + 1)) {
            $startAddr = unpack('N', $d, $p - 8)[1];
            $endAddr = unpack('N', $d, $p - 4)[1];
            $base = $startAddr - ($p - self::MAGIC_OFFSET);
            $s = $startAddr - $base;
            $e = $endAddr - $base;
            // Real regions are 0x100 aligned; stray marker copies inside data are not.
            if ($s < 0 || $s >= $e || $e >= $size || $s % 0x100 !== 0 || ($e + 1) % 0x100 !== 0) {
                continue;
            }
            $out[] = [$s, $e];
        }

        return $out;
    }

    /** Sum of BE dwords over [start, end inclusive], mod 2^32. */
    private function sum(string $d, int $start, int $end): int
    {
        $sum = 0;
        for ($o = $start; $o <= $end; $o += 0x40000) {
            $len = min(0x40000, $end + 1 - $o);
            $sum += array_sum(unpack('N*', substr($d, $o, $len)));
        }

        return $sum & 0xFFFFFFFF;
    }
}
