<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;
use RuntimeException;

/**
 * Bosch EDC15C BMW (DDE4, M47/M57, C167, 512 KB) checksums.
 *
 * Layout (taken from the firmware: the TSW routine at 0xFE6E0 / 0x91002 calls the boot ROM
 * sum routine per range and requires the 32-bit result r6:r1 == 0x4711B530):
 *   0x10000-0x13FFF  program/TSW block   checksum dword at 0x13FFC
 *   0x14000-0x70B7F  program + data      checksum dword at 0x70B7C
 *   0x70B80-0x7BFFF  calibration         checksum dword at 0x7BFFC
 * Each checksum dword sits right after a 2-byte block id (0x97C1 on the samples) repeated
 * in front of all three.
 *
 * The sum is the Bosch "TDI v4.1" rolling 16-bit pair (same structure as VAG EDC15), but with
 * add constant 0x9294, start state (0,0) and closure target (a,b) = (0xB530,0x4711).
 * Run over a whole block *including* its checksum dword, a valid block ends in that state;
 * the checksum is the dword that forces it.
 *
 * Verified on 7 stock/flashed/WinOLS-corrected M57 dumps; fix() reproduces the WinOLS output
 * byte-for-byte. Also accepts a 48 KB calibration-only file (flash 0x70000-0x7BFFF), where
 * only the calibration block can be checked.
 */
class Edc15Checksum implements ChecksumModule
{
    private const M16 = 0xFFFF;

    private const ADD = 0x9294;

    private const TARGET_A = 0xB530;

    private const TARGET_B = 0x4711;

    /** [name, start, end (exclusive)]; checksum dword at end-4. */
    private const FULL = [
        ['Program/TSW 0x10000-0x13FFF', 0x10000, 0x14000],
        ['Program+data 0x14000-0x70B7F', 0x14000, 0x70B80],
        ['Calibration 0x70B80-0x7BFFF', 0x70B80, 0x7C000],
    ];

    /** 48 KB calibration-only read starting at flash 0x70000. */
    private const PARTIAL = [
        ['Calibration 0x70B80-0x7BFFF', 0x0B80, 0xC000],
    ];

    public function name(): string
    {
        return 'Bosch EDC15C BMW (DDE4)';
    }

    public function supports(Bin $bin): bool
    {
        $blocks = $this->layout($bin);
        if ($blocks === null) {
            return false;
        }
        if ($bin->size === 0x80000) {
            // Strong fingerprint: at least one block must already close to 0x4711B530
            // (a random hit is 1 in 2^32). Tunes normally leave the TSW block untouched.
            foreach ($blocks as [, $s, $e]) {
                if ($this->stored($bin->data, $e) === $this->calc($bin->data, $s, $e)) {
                    return true;
                }
            }

            return false;
        }

        // Partial: BMW software number in the calibration and not a VAG v4.1 block.
        return preg_match('/^1037\d{6}$/', substr($bin->data, 0xBFB0, 10)) === 1
            && ! $this->isVagV41($bin->data, 0x0B80, 0xC000);
    }

    public function check(Bin $bin): array
    {
        $blocks = $this->layout($bin) ?? throw new RuntimeException('Not an EDC15C BMW layout.');
        $out = [];
        foreach ($blocks as [$name, $s, $e]) {
            $stored = $this->stored($bin->data, $e);
            $calc = $this->calc($bin->data, $s, $e);
            $out[] = [
                'name' => $name,
                'start' => $s,
                'end' => $e - 1,
                'stored' => sprintf('%08X', $stored),
                'calculated' => sprintf('%08X', $calc),
                'ok' => $stored === $calc,
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
            throw new RuntimeException('Not an EDC15C BMW layout.');
        }
        $data = $bin->data;
        // Ranges do not overlap other checksum dwords, so order does not matter.
        foreach ($this->layout($bin) as [, $s, $e]) {
            $calc = $this->calc($data, $s, $e);
            if ($this->stored($data, $e) !== $calc) {
                $data = substr_replace($data, pack('V', $calc), $e - 4, 4);
            }
        }
        $fixed = new Bin($data);
        foreach ($this->check($fixed) as $b) {
            if (! $b['ok']) {
                throw new RuntimeException("Checksum correction failed for {$b['name']}.");
            }
        }

        return $fixed;
    }

    /** @return array<int, array{0:string,1:int,2:int}>|null */
    private function layout(Bin $bin): ?array
    {
        $d = $bin->data;
        if ($bin->size === 0x80000) {
            $base = 0x70000;
            $blocks = self::FULL;
            if (substr($d, 0x10000, 4) !== "\xC3\x3C\x55\xAA") {
                return null;
            }
        } elseif ($bin->size === 0xC000) {
            $base = 0;
            $blocks = self::PARTIAL;
        } else {
            return null;
        }
        // Calibration header: F9 67 FF FF FF FF FF FF "Vx.y"
        if (substr($d, $base + 1, 7) !== "\x67\xFF\xFF\xFF\xFF\xFF\xFF"
            || ! preg_match('/^V\d\.\d$/', substr($d, $base + 8, 4))) {
            return null;
        }
        // Same block id in front of every checksum (and at cal+0xB7A in a partial).
        $id = substr($d, $blocks[0][2] - 6, 2);
        if (in_array($id, ["\xC3\xC3", "\xFF\xFF", "\x00\x00"], true)) {
            return null;
        }
        $marks = array_map(fn ($b) => $b[2] - 6, $blocks);
        if ($bin->size === 0xC000) {
            $marks[] = 0x0B7A;
        }
        foreach ($marks as $o) {
            if (substr($d, $o, 2) !== $id) {
                return null;
            }
        }

        return $blocks;
    }

    private function stored(string $d, int $end): int
    {
        return unpack('V', $d, $end - 4)[1];
    }

    /** Checksum dword that closes [start,end) to (0xB530,0x4711). */
    private function calc(string $d, int $start, int $end): int
    {
        // State after all words before the checksum (last group stops after word 1).
        [$a, $b] = $this->run($d, $start, $end - 4, 0, 0, self::ADD);
        // Word 2: a = a - lo + ADD must hit TARGET_A. Word 3: b ^= hi; TARGET_A & 15 == 0, so no rotate.
        $lo = ($a + self::ADD - self::TARGET_A) & self::M16;
        $hi = ($b ^ self::TARGET_B) & self::M16;

        return ($hi << 16) | $lo;
    }

    /** VAG EDC15 "TDI v4.1" calibration block closes to (0x8631,0xEFCD) from the same seed. */
    private function isVagV41(string $d, int $start, int $end): bool
    {
        return $this->run($d, $start, $end, 0x8631, 0xEFCD, 0xDAAC) === [0x8631, 0xEFCD];
    }

    /**
     * Bosch rolling pair over LE words [start,end), groups of 4 words:
     *   a ^= w0; a = rol(a, b&15); c = a&1;  b = (b - w1 - c) ^ a;
     *   a = a - w2 + add;  b ^= w3;  b = ror(b, a&15)
     * A trailing half group (w0,w1) is allowed.
     *
     * @return array{0:int,1:int}
     */
    private function run(string $d, int $start, int $end, int $a, int $b, int $add): array
    {
        $w = array_values(unpack('v*', substr($d, $start, $end - $start)));
        $n = count($w);
        for ($i = 0; $i < $n; $i += 4) {
            $a ^= $w[$i];
            $c = 0;
            if ($r = $b & 15) {
                $a = (($a << $r) | ($a >> (16 - $r))) & 0xFFFF;
                $c = $a & 1;
            }
            $b = (($b - $w[$i + 1] - $c) & 0xFFFF) ^ $a;
            if ($i + 2 >= $n) {
                break;
            }
            $a = ($a - $w[$i + 2] + $add) & 0xFFFF;
            $b ^= $w[$i + 3];
            if ($r = $a & 15) {
                $b = (($b >> $r) | ($b << (16 - $r))) & 0xFFFF;
            }
        }

        return [$a, $b];
    }
}
