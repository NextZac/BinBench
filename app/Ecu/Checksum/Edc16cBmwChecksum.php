<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;
use App\Ecu\Families\Edc16\BmwEdc16cLayout;
use RuntimeException;

/**
 * Bosch EDC16C BMW (DDE5, MPC555) full reads of 1.5 MB.
 *
 * Same sum as VAG EDC16U: every protected block has a header with the marker FA DE CA FE CA FE AF FE;
 * the two big-endian dwords before the marker are the block's start and inclusive end (CPU address),
 * and the sum of all big-endian dwords of the block, its last dword included, must be 0xD01FE500.
 * The last dword is the compensation value. Unlike on EDC16U, the header is not at the block start
 * and the address space is split (see BmwEdc16cLayout), so blocks are taken from the header contents.
 *
 * Verified on SW 1037361878 (X5 E53): all 7 blocks close in the stock read (startup, internal
 * application, external boot, loader, loader head, external application, data).
 * The startup block is never corrected. The data block also carries a 64-byte signature at
 * 0x8DFF00 that this sum does not cover: OBD programming may reject modified code even with
 * correct sums, a BDM/boot-mode write does not check it.
 */
class Edc16cBmwChecksum implements ChecksumModule
{
    private const MAGIC = "\xFA\xDE\xCA\xFE\xCA\xFE\xAF\xFE";

    private const TARGET = 0xD01FE500;

    /** CPU start of the startup block (reset vectors), which must not be rewritten. */
    private const STARTUP = 0x0;

    public function name(): string
    {
        return 'Bosch EDC16C BMW (DDE5)';
    }

    public function supports(Bin $bin): bool
    {
        if (! BmwEdc16cLayout::matches($bin)) {
            return false;
        }
        foreach ($this->blocks($bin->data) as [, $s, $e]) {
            if ($this->sum($bin->data, $s, $e) === self::TARGET) {
                return true;
            }
        }

        return false;
    }

    public function check(Bin $bin): array
    {
        $out = [];
        foreach ($this->blocks($bin->data) as [$cpu, $s, $e]) {
            $sum = $this->sum($bin->data, $s, $e);
            $stored = unpack('N', $bin->data, $e - 3)[1];
            $out[] = [
                'name' => sprintf('%s 0x%06X-0x%06X', $this->label($cpu), $cpu, BmwEdc16cLayout::fileToCpu($e)),
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
        return true;
    }

    public function fix(Bin $bin): Bin
    {
        if (! $this->supports($bin)) {
            throw new RuntimeException('Not a BMW EDC16C full read.');
        }
        $data = $bin->data;
        foreach ($this->blocks($data) as [$cpu, $s, $e]) {
            $sum = $this->sum($data, $s, $e);
            if ($sum === self::TARGET) {
                continue;
            }
            if ($cpu === self::STARTUP) {
                throw new RuntimeException('The startup block (reset vectors) was changed. BinBench does not correct it.');
            }
            $stored = unpack('N', $data, $e - 3)[1];
            $data = substr_replace($data, pack('N', ($stored + self::TARGET - $sum) & 0xFFFFFFFF), $e - 3, 4);
        }

        $fixed = new Bin($data);
        foreach ($this->check($fixed) as $block) {
            if (! $block['ok']) {
                throw new RuntimeException("Checksum correction failed for {$block['name']}.");
            }
        }

        return $fixed;
    }

    /**
     * Protected blocks as [CPU start, file start, file end inclusive], one per distinct range.
     *
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    private function blocks(string $d): array
    {
        $out = [];
        for ($p = strpos($d, self::MAGIC, 8); $p !== false; $p = strpos($d, self::MAGIC, $p + 1)) {
            [$startCpu, $endCpu] = array_values(unpack('N2', $d, $p - 8));
            $s = BmwEdc16cLayout::cpuToFile($startCpu);
            $e = BmwEdc16cLayout::cpuToFile($endCpu);
            // both ends in one flash, a whole number of dwords, and the header inside its block
            if ($s === null || $e === null || $s >= $e || ($e - $s + 1) % 4 !== 0
                || ($startCpu < 0x800000) !== ($endCpu < 0x800000) || isset($out[$startCpu])) {
                continue;
            }
            $out[$startCpu] = [$startCpu, $s, $e];
        }
        ksort($out);

        return array_values($out);
    }

    private function label(int $cpu): string
    {
        return match (true) {
            $cpu === self::STARTUP => 'Startup',
            $cpu < 0x800000 => 'Internal application',
            $cpu < 0x820000 => 'External boot',
            $cpu < 0x840000 => 'Loader',
            $cpu < 0x8C0000 => 'External application',
            default => 'Data',
        };
    }

    /** Sum of big-endian dwords over [start, end inclusive], mod 2^32. */
    private function sum(string $d, int $start, int $end): int
    {
        $sum = 0;
        for ($o = $start; $o <= $end; $o += 0x40000) {
            $sum += array_sum(unpack('N*', substr($d, $o, min(0x40000, $end + 1 - $o))));
        }

        return $sum & 0xFFFFFFFF;
    }
}
