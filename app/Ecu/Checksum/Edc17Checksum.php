<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;
use RuntimeException;

/**
 * Bosch EDC17 / MED17 (TriCore, little-endian) block checksums. VERIFY ONLY.
 *
 * Structure as documented by ConnorHowell/medc17-checksum-tool (MIT); re-implemented here,
 * NOT verified against real files in this project (no EDC17 samples available):
 *   - each Bosch block header holds FADECAFE/CAFEAFFE at +0x40, type byte at +0x00
 *     (0x10 startup, 0x40 ASW, 0x60 dataset, ...), class 0x80/0xA0 at +0x0F, size at +0x04,
 *     end address at +0x0C, structure count at +0x2C, and ends with DEADBEEF;
 *   - up to 8 32-byte checksum structures at +0x34: range start/end address, seed, expected
 *     value, algorithm (0x00 CRC32, 0x01 ADD32, 0x10 ADD16).
 * Not covered, and the reason fix() is disabled: the RSA signature over the application,
 * per-variant monitoring checksums, CVN, and the compensation-word solving.
 */
class Edc17Checksum implements ChecksumModule
{
    private const MAGIC_PAIR = "\xFE\xCA\xDE\xFA\xFE\xAF\xFE\xCA";

    private const BLOCK_NAMES = [
        0x10 => 'Startup', 0x20 => 'Tuning protection', 0x30 => 'Customer', 0x40 => 'ASW #0',
        0x50 => 'ASW #1', 0x60 => 'Dataset #0', 0x70 => 'Dataset #1', 0x80 => 'Variant dataset',
        0x90 => 'Customer tuning protection', 0xA0 => 'ASW #2', 0xB0 => 'ASW #3',
        0xC0 => 'Absolute constants', 0xD0 => 'Emulation extension', 0xE0 => 'Customer specific',
        0xF0 => 'Ramloader', 0xF1 => 'Application attestation',
    ];

    private const ALGOS = [0x00 => 'CRC32', 0x01 => 'ADD32', 0x10 => 'ADD16'];

    /** @var array<int,int>|null */
    private static ?array $crcTable = null;

    public function name(): string
    {
        return 'Bosch EDC17/MED17 (verify only)';
    }

    public function supports(Bin $bin): bool
    {
        if ($bin->size < 0x40000 || $bin->size > 0x800000) {
            return false;
        }
        foreach ($this->blocks($bin->data) as $block) {
            foreach ($block['structs'] as $cs) {
                if (isset(self::ALGOS[$cs['algo']])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function check(Bin $bin): array
    {
        $d = $bin->data;
        $out = [];
        foreach ($this->blocks($d) as $block) {
            foreach ($block['structs'] as $i => $cs) {
                $algo = self::ALGOS[$cs['algo']] ?? sprintf('algo 0x%02X', $cs['algo']);
                $s = $this->toFile($cs['start'], $block);
                $e = $this->toFile($cs['end'], $block);
                $name = sprintf('%s @0x%X #%d %s', self::BLOCK_NAMES[$block['type']], $block['offset'], $i + 1, $algo);
                $calc = null;
                if ($s >= 0 && $e < strlen($d) && $s < $e) {
                    $calc = match ($cs['algo']) {
                        0x00 => ~$this->crc32($d, $s, $e, $cs['seed']) & 0xFFFFFFFF, // CRC result is the complement
                        0x01 => $this->add32($d, $s, $e, $cs['seed']),
                        0x10 => $this->add16($d, $s, $e, $cs['seed']),
                        default => null,
                    };
                }
                $out[] = [
                    'name' => $name,
                    'start' => max($s, 0),
                    'end' => max($e, 0),
                    'stored' => sprintf('%08X', $cs['expected']),
                    'calculated' => $calc === null ? '' : sprintf('%08X', $calc),
                    'ok' => $calc === $cs['expected'],
                ];
            }
        }

        return $out;
    }

    public function canFix(): bool
    {
        return false;
    }

    public function fix(Bin $bin): Bin
    {
        throw new RuntimeException('EDC17 checksum correction is not supported (RSA signature, variant monitoring and CVN are not handled).');
    }

    /**
     * Parsed Bosch blocks.
     *
     * @return array<int, array{offset:int,type:int,startAddr:int,structs:array<int,array{start:int,end:int,seed:int,expected:int,algo:int}>}>
     */
    private function blocks(string $d): array
    {
        $len = strlen($d);
        $out = [];
        for ($p = strpos($d, self::MAGIC_PAIR); $p !== false; $p = strpos($d, self::MAGIC_PAIR, $p + 1)) {
            $h = $p - 0x40;
            if ($h < 0 || $h + 0x48 > $len) {
                continue;
            }
            $type = ord($d[$h]);
            if (! isset(self::BLOCK_NAMES[$type]) || $d[$h + 1] !== "\0" || $d[$h + 3] !== "\0"
                || ! in_array(ord($d[$h + 15]), [0x80, 0xA0], true)) {
                continue;
            }
            $size = $this->u32($d, $h + 4);
            $endAddr = $this->u32($d, $h + 12);
            $count = $this->u32($d, $h + 0x2C);
            if ($size < 0x40 || $h + $size > $len || $this->u32($d, $h + $size - 4) !== 0xDEADBEEF
                || $count < 1 || $count > 8 || ! $this->isFlash($endAddr) || ! $this->isFlash($endAddr + 4 - $size)) {
                continue;
            }
            $structs = [];
            for ($i = 0; $i < $count; $i++) {
                $o = $h + 0x34 + $i * 32;
                $structs[] = [
                    'start' => $this->u32($d, $o + 4),
                    'end' => $this->u32($d, $o + 8),
                    'seed' => $this->u32($d, $o + 12),
                    'expected' => $this->u32($d, $o + 16),
                    'algo' => ord($d[$o + 28]),
                ];
            }
            $out[] = ['offset' => $h, 'type' => $type, 'startAddr' => $endAddr + 4 - $size, 'structs' => $structs];
        }

        return $out;
    }

    private function u32(string $d, int $o): int
    {
        return unpack('V', $d, $o)[1];
    }

    private function isFlash(int $addr): bool
    {
        return ($addr >= 0x80000000 && $addr <= 0x8FFFFFFF) || ($addr >= 0xA0000000 && $addr <= 0xAFFFFFFF);
    }

    /** Flash address -> file offset relative to its block (0x8/0xA segment aliases are the same flash). */
    private function toFile(int $addr, array $block): int
    {
        return ($addr & 0x0FFFFFFF) - ($block['startAddr'] & 0x0FFFFFFF) + $block['offset'];
    }

    /** Reflected CRC32 (0xEDB88320) over whole LE dwords of [start, end], given init, no final xor. */
    private function crc32(string $d, int $start, int $end, int $init): int
    {
        $len = intdiv($end - $start + 1, 4) * 4;
        // hash('crc32b') uses init/xorout 0xFFFFFFFF: prepend 4 bytes that steer the register to $init.
        $crc = hexdec(hash('crc32b', pack('V', $this->unshift4($init) ^ 0xFFFFFFFF).substr($d, $start, $len)));

        return $crc ^ 0xFFFFFFFF;
    }

    /** Register R with R run through 4 zero bytes == $reg. */
    private function unshift4(int $reg): int
    {
        if (self::$crcTable === null) {
            for ($i = 0; $i < 256; $i++) {
                $c = $i;
                for ($k = 0; $k < 8; $k++) {
                    $c = $c & 1 ? ($c >> 1) ^ 0xEDB88320 : $c >> 1;
                }
                self::$crcTable[$i] = $c;
            }
        }
        $byTop = [];
        foreach (self::$crcTable as $j => $t) {
            $byTop[$t >> 24] = $j;
        }
        for ($k = 0; $k < 4; $k++) {
            $j = $byTop[$reg >> 24];
            $reg = ((($reg ^ self::$crcTable[$j]) << 8) & 0xFFFFFFFF) | $j;
        }

        return $reg;
    }

    private function add32(string $d, int $start, int $end, int $seed): int
    {
        $sum = $seed;
        $len = intdiv($end - $start + 1, 4) * 4;
        for ($o = 0; $o < $len; $o += 0x40000) {
            $sum = ($sum + array_sum(unpack('V*', substr($d, $start + $o, min(0x40000, $len - $o))))) & 0xFFFFFFFF;
        }

        return $sum;
    }

    /** Sum of LE words; the final word is added shifted into the high half (Bosch quirk). */
    private function add16(string $d, int $start, int $end, int $seed): int
    {
        $len = $end - 2 >= $start ? intdiv($end - 2 - $start, 2) * 2 + 2 : 0; // words at pos <= end-2
        $sum = $seed;
        for ($o = 0; $o < $len; $o += 0x40000) {
            $sum = ($sum + array_sum(unpack('v*', substr($d, $start + $o, min(0x40000, $len - $o))))) & 0xFFFFFFFF;
        }
        $last = unpack('v', $d, $start + $len)[1];

        return ($sum + ($last << 16)) & 0xFFFFFFFF;
    }
}
