<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;
use RuntimeException;

/**
 * Bosch ME9.0 Ford/Volvo (Focus RS mk2, MPC5xx, big-endian, 2 MB flash) checksums.
 *
 * Reverse-engineered from the firmware of a 2010 Focus RS boot read:
 *
 * 1. Multipoint word sums (runtime, application background task at 0xBA1C8 / 0x23B40):
 *    16-byte descriptors {start, end (inclusive), sum, ~sum}, sum = 32-bit sum of big-endian
 *    16-bit words. Tables: boot 0x1FF0 + 0x1FFC0..0x1FFFF, program 0xA0210.., calibration
 *    0x1CA8A0.. (cal descriptors use the 0x5C0000 mirror address = flash + 0x400000).
 *    A descriptor's own {sum,~sum} adds a constant 0x1FFFE to any containing sum, so the
 *    tables never depend on each other.
 *
 * 2. Per-segment CRC32 (customer bootloader download check, routine 0x848B8 -> 0x83A54):
 *    standard reflected CRC-32 (poly 0xEDB88320, init/xorout 0xFFFFFFFF, i.e. zlib crc32)
 *    over the whole flash segment, with the stored pair {crc, ~crc} at hdr+0x38 and the
 *    AFFE0815 "programmed" block at hdr+0x80..hdr+0xBF taken as 0xFF (that block is only
 *    written after the check passed). Segments (bootloader table at 0x81FB0):
 *      0x020000-0x07FFFF hdr 0x020030 | 0x080000-0x09FFFF hdr 0x080100
 *      0x0A0000-0x1BFFFF hdr 0x0A0000 | 0x1C2000-0x1DFFFF hdr 0x1C3200 (calibration)
 *      0x1E0000-0x1FFFFF hdr 0x1E4000
 *
 * The RSA signature at hdr+0x100 (MD5 + RSA, modulus at 0x88110) covers only the range list
 * at hdr+0x180, which is the customer bootloader 0x80280-0x96548 in every segment, so it
 * does not depend on calibration or program contents and needs no update.
 *
 * Also accepts a 128 KB calibration-only file (flash 0x1C0000-0x1DFFFF).
 */
class Me9Checksum implements ChecksumModule
{
    private const M32 = 0xFFFFFFFF;

    private const FULL_SIZE = 0x200000;

    private const CAL_SIZE = 0x20000;

    private const CAL_BASE = 0x1C0000;

    /** Calibration mirror used by the application (r2 = 0x5CB1F0). */
    private const MIRROR = 0x400000;

    /** [name, start, end (inclusive)] flash segments with a CRC header near the start. */
    private const SEGMENTS = [
        ['Program 0x020000-0x07FFFF', 0x020000, 0x07FFFF],
        ['Bootloader 0x080000-0x09FFFF', 0x080000, 0x09FFFF],
        ['Program 0x0A0000-0x1BFFFF', 0x0A0000, 0x1BFFFF],
        ['Calibration 0x1C2000-0x1DFFFF', 0x1C2000, 0x1DFFFF],
        ['Program 0x1E0000-0x1FFFFF', 0x1E0000, 0x1FFFFF],
    ];

    private const CAL_SEGMENT = 3;

    /** Boot descriptors that stand alone (no run of neighbours). */
    private const BOOT_SINGLES = [0x1FF0];

    /** Minimum run of adjacent descriptors accepted elsewhere (avoids random data hits). */
    private const MIN_RUN = 3;

    public function name(): string
    {
        return 'Bosch ME9.0 Ford/Volvo';
    }

    public function supports(Bin $bin): bool
    {
        $layout = $this->layout($bin);
        if ($layout === null) {
            return false;
        }
        if ($layout['base'] === 0) {
            // Strong fingerprint: a segment CRC must already match (tunes leave the program alone).
            foreach ($layout['crc'] as $seg) {
                if ($this->storedCrc($bin->data, $seg) === $this->calcCrc($bin->data, $seg)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    public function check(Bin $bin): array
    {
        $layout = $this->layout($bin) ?? throw new RuntimeException('Not a Bosch ME9.0 Ford/Volvo layout.');

        return $this->blocks($bin->data, $layout);
    }

    public function canFix(): bool
    {
        return true;
    }

    public function fix(Bin $bin): Bin
    {
        if (! $this->supports($bin)) {
            throw new RuntimeException('Not a Bosch ME9.0 Ford/Volvo layout.');
        }
        $layout = $this->layout($bin);
        $d = $bin->data;

        // Word sums first: the segment CRCs cover the descriptor tables.
        foreach ($layout['sums'] as $e) {
            $calc = $this->wordSum($d, $e['from'], $e['to']);
            $d = substr_replace($d, pack('N2', $calc, ~$calc & self::M32), $e['at'] + 8, 8);
        }
        foreach ($layout['crc'] as $seg) {
            $calc = $this->calcCrc($d, $seg);
            $d = substr_replace($d, pack('N2', $calc, ~$calc & self::M32), $seg['hdr'] + 0x38, 8);
        }

        $fixed = new Bin($d);
        foreach ($this->blocks($d, $layout) as $b) {
            if (! $b['ok']) {
                throw new RuntimeException("Checksum correction failed for {$b['name']}.");
            }
        }

        return $fixed;
    }

    /**
     * @param  array{base:int,crc:array,sums:array}  $layout
     * @return array<int, array{name:string,start:int,end:int,stored:string,calculated:string,ok:bool}>
     */
    private function blocks(string $d, array $layout): array
    {
        $out = [];
        foreach ($layout['sums'] as $e) {
            [$sum, $inv] = array_values(unpack('N2', $d, $e['at'] + 8));
            $calc = $this->wordSum($d, $e['from'], $e['to']);
            $out[] = [
                'name' => sprintf('Word sum 0x%06X-0x%06X (table @0x%06X)', $e['from'] + $layout['base'], $e['to'] + $layout['base'], $e['at'] + $layout['base']),
                'start' => $e['from'],
                'end' => $e['to'],
                'stored' => sprintf('%08X', $sum),
                'calculated' => sprintf('%08X', $calc),
                'ok' => $sum === $calc && $inv === (~$calc & self::M32),
            ];
        }
        foreach ($layout['crc'] as $seg) {
            $stored = $this->storedCrc($d, $seg);
            $calc = $this->calcCrc($d, $seg);
            $inv = unpack('N', $d, $seg['hdr'] + 0x3C)[1];
            $out[] = [
                'name' => sprintf('CRC32 %s (hdr @0x%06X)', $seg['name'], $seg['hdr'] + $layout['base']),
                'start' => $seg['start'],
                'end' => $seg['end'],
                'stored' => sprintf('%08X', $stored),
                'calculated' => sprintf('%08X', $calc),
                'ok' => $stored === $calc && $inv === (~$calc & self::M32),
            ];
        }

        return $out;
    }

    /**
     * File-relative layout, or null if this is not an ME9 Ford image.
     *
     * @return array{base:int,crc:array<int,array{name:string,start:int,end:int,hdr:int}>,sums:array<int,array{at:int,from:int,to:int}>}|null
     */
    private function layout(Bin $bin): ?array
    {
        $d = $bin->data;
        if ($bin->size === self::FULL_SIZE) {
            $base = 0;
            $segments = self::SEGMENTS;
        } elseif ($bin->size === self::CAL_SIZE) {
            $base = self::CAL_BASE;
            $segments = [self::CAL_SEGMENT => self::SEGMENTS[self::CAL_SEGMENT]];
        } else {
            return null;
        }

        $crc = [];
        foreach ($segments as $i => [$name, $start, $end]) {
            $hdr = $this->findHeader($d, $start - $base, $i === self::CAL_SEGMENT, $base);
            if ($hdr === null) {
                return null;
            }
            $crc[] = ['name' => $name, 'start' => $start - $base, 'end' => $end - $base, 'hdr' => $hdr];
        }

        $sums = $this->findDescriptors($d, $base);
        if ($base === 0 ? count($sums) < 8 : $sums === []) {
            return null;
        }

        return ['base' => $base, 'crc' => $crc, 'sums' => $sums];
    }

    /**
     * Segment header {5A5A5A5A, 33333333|CCCCCCCC, hdr+0x80, hdr+0x40, hdr+0xC0, ...}
     * within the first 32 KB of a segment, 16-byte aligned.
     */
    private function findHeader(string $d, int $segStart, bool $cal, int $base): ?int
    {
        $magic = "\x5A\x5A\x5A\x5A".($cal ? "\xCC\xCC\xCC\xCC" : "\x33\x33\x33\x33");
        $limit = min(strlen($d), $segStart + 0x8000);
        for ($o = $segStart; ($o = strpos($d, $magic, $o)) !== false && $o < $limit; $o++) {
            if ($o % 16 !== 0 || $o + 0x200 > strlen($d)) {
                continue;
            }
            $flash = $o + $base;
            [$p1, $p2, $p3] = array_values(unpack('N3', $d, $o + 8));
            if ($p1 === $flash + 0x80 && $p2 === $flash + 0x40 && $p3 === $flash + 0xC0) {
                return $o;
            }
        }

        return null;
    }

    /**
     * Multipoint descriptors {start, end, sum, ~sum}: runs of >= MIN_RUN adjacent valid entries
     * anywhere, plus the stand-alone boot entries.
     *
     * @return array<int, array{at:int,from:int,to:int}>
     */
    private function findDescriptors(string $d, int $base): array
    {
        $len = strlen($d);
        $valid = [];
        for ($chunk = 0; $chunk < $len - 12; $chunk += 0x10000) {
            $w = array_values(unpack('N*', substr($d, $chunk, min(0x10000 + 12, $len - $chunk))));
            $n = intdiv(min(0x10000, $len - 12 - $chunk) + 3, 4);
            for ($i = 0; $i < $n; $i++) {
                if (($w[$i + 2] ^ $w[$i + 3]) !== self::M32) {
                    continue;
                }
                $from = $this->toFile($w[$i], $base, $len);
                $to = $this->toFile($w[$i + 1], $base, $len);
                if ($from === null || $to === null || $to - $from < 0xF || ($to - $from + 1) % 2 !== 0) {
                    continue;
                }
                $valid[$chunk + $i * 4] = ['at' => $chunk + $i * 4, 'from' => $from, 'to' => $to];
            }
        }

        $out = [];
        foreach ($valid as $at => $e) {
            $run = 1;
            for ($k = $at - 16; isset($valid[$k]); $k -= 16) {
                $run++;
            }
            for ($k = $at + 16; isset($valid[$k]); $k += 16) {
                $run++;
            }
            if ($run >= self::MIN_RUN || ($base === 0 && in_array($at, self::BOOT_SINGLES, true))) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /** Map a flash or 0x5C0000-mirror address to a file offset. */
    private function toFile(int $addr, int $base, int $len): ?int
    {
        if ($addr >= self::CAL_BASE + self::MIRROR && $addr < self::CAL_BASE + self::MIRROR + self::CAL_SIZE) {
            $addr -= self::MIRROR;
        }
        $o = $addr - $base;

        return $o >= 0 && $o < $len ? $o : null;
    }

    private function wordSum(string $d, int $from, int $to): int
    {
        $sum = 0;
        for ($o = $from; $o <= $to; $o += 0x8000) {
            $sum += array_sum(unpack('n*', substr($d, $o, min(0x8000, $to + 1 - $o))));
        }

        return $sum & self::M32;
    }

    /** @param array{start:int,end:int,hdr:int} $seg */
    private function storedCrc(string $d, array $seg): int
    {
        return unpack('N', $d, $seg['hdr'] + 0x38)[1];
    }

    /** @param array{start:int,end:int,hdr:int} $seg */
    private function calcCrc(string $d, array $seg): int
    {
        $h = $seg['hdr'];
        $data = substr($d, $seg['start'], $h + 0x38 - $seg['start'])
            .str_repeat("\xFF", 8)
            .substr($d, $h + 0x40, 0x40)
            .str_repeat("\xFF", 0x40)
            .substr($d, $h + 0xC0, $seg['end'] + 1 - ($h + 0xC0));

        return crc32($data);
    }
}
