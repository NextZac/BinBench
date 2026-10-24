<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;
use RuntimeException;

/**
 * Bosch ME7.x (ME7.1 / ME7.1.1 / ME7.5, VAG C167 512KB/1MB flash dumps) checksums.
 *
 * Port of nyetwurk's ME7Sum v1.1.3 (BSD, https://github.com/nyetwurk/ME7Sum), including
 * its autodetection, step order and in-place sequential fixing, so a single fix pass
 * produces the same bytes as `me7sum in.bin out.bin`. Blocks handled:
 *   - ROMSYS startup / all-param-page / program-pages additive sums
 *   - CRC32 lookup table integrity (tuners sometimes alter it)
 *   - RSA signature over MD5 ranges (verify only; re-signing is not supported)
 *   - Main data area CRC32 blocks and/or bytewise main data checksum (ME7.1.1)
 *   - Main program checksum (word sum + inverse at end of flash)
 *   - Multipoint checksum descriptor tables #1 and #2 (incl. boot ROM whitelist)
 *   - ME7Sum "rechecks": multipoint blocks whose data contains a later checksum
 */
class Me7Checksum implements ChecksumModule
{
    private const BASE = 0x800000;

    private const M32 = 0xFFFFFFFF;

    private const MP_DESC_LEN = 0x10;

    private const MAX_MP_BLOCKS = 66;   // 2 bootrom + 64 main descriptors, no end marker

    private const MAX_CRC_BLKS = 4;

    private const RSA_BLOCK = 128;

    private const MAX_PASSES = 5;

    // Per-run state (reset by run())
    private string $d = '';

    private int $len = 0;

    private string $pad = 'none';

    private bool $readonly = true;

    private bool $whitelisted = false;

    private int $found = 0;

    private int $uncorrectable = 0;

    private int $errors = 0;

    private int $corrected = 0;

    /** @var array<int,array> */
    private array $blocks = [];

    /** @var string[] */
    private array $problems = [];

    /** @var array<int,array> ME7Sum report records used for recheck dependencies */
    private array $records = [];

    public function name(): string
    {
        return 'Bosch ME7.x (ME7Sum)';
    }

    public function supports(Bin $bin): bool
    {
        if ($bin->size !== 0x80000 && $bin->size !== 0x100000) {
            return false;
        }
        $this->reset($bin->data, true);
        $this->initPadding();

        // The main multipoint table (boot ROM descriptors 0-0x3FFF) is the ME7 hallmark.
        return $this->findChecksumBlks(1) > 0;
    }

    public function check(Bin $bin): array
    {
        $this->run($bin->data, true);

        return $this->publicBlocks();
    }

    public function canFix(): bool
    {
        return true;
    }

    public function fix(Bin $bin): Bin
    {
        $data = $bin->data;
        // ME7Sum may need several passes when a fixed checksum sits inside another block's data.
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $this->run($data, true);
            if ($this->uncorrectable > 0) {
                throw new RuntimeException('ME7 checksum autodetection failed: '.implode('; ', $this->problems));
            }
            if ($this->errors === 0) {
                return new Bin($data);
            }
            $this->run($data, false);
            if ($this->uncorrectable > 0) {
                throw new RuntimeException('ME7 checksums could not be corrected: '.implode('; ', $this->problems));
            }
            $data = $this->output();
        }
        $this->run($data, true);
        if ($this->uncorrectable > 0 || $this->errors > 0) {
            throw new RuntimeException('ME7 checksums still invalid after '.self::MAX_PASSES.' passes');
        }

        return new Bin($data);
    }

    // ---------------------------------------------------------------- driver

    private function reset(string $data, bool $readonly): void
    {
        $this->d = $data;
        $this->len = strlen($data);
        $this->pad = 'none';
        $this->readonly = $readonly;
        $this->whitelisted = false;
        $this->found = $this->uncorrectable = $this->errors = $this->corrected = 0;
        $this->blocks = $this->problems = $this->records = [];
    }

    private function initPadding(): void
    {
        if ($this->len !== 0x100000) {
            return;
        }
        $hi = substr($this->d, 0x80000);
        if ($hi === substr($this->d, 0, 0x80000)) {
            $this->len = 0x80000;
            $this->pad = 'doubled';
        } elseif ($hi === str_repeat("\xFF", 0x80000)) {
            $this->len = 0x80000;
            $this->pad = 'ff';
        } elseif (substr($hi, 0, 0x80000 - 32) === str_repeat("\xFF", 0x80000 - 32)) {
            $this->pad = 'try512';
        }
    }

    /** Corrected image in the original file layout. */
    private function output(): string
    {
        if ($this->pad === 'doubled') {
            $lo = substr($this->d, 0, 0x80000);

            return $lo.$lo;
        }

        return $this->d;
    }

    /** One full ME7Sum run, same step order as me7sum.c main(). */
    private function run(string $data, bool $readonly): void
    {
        $this->reset($data, $readonly);
        if ($this->len !== 0x80000 && $this->len !== 0x100000) {
            $this->fail('File size', 'not a 512KB/1MB dump');

            return;
        }
        $this->initPadding();

        // 1: ROM info (only the ECUID table matters: ME7Sum aborts without it)
        if (! $this->findEcuId()) {
            $this->fail('ROM info', 'ECUID table not found');
        }

        // 2: ROMSYS
        $romsys = 0x8000;
        $this->doRomsys($romsys);

        // 3: CRC table(s)
        $crctab = $this->findCrcTab();
        if ($crctab) {
            $this->doCrcTab($crctab);
        } else {
            $this->fail('CRC table', 'not found');
        }

        // 4: RSA
        $rsa = $this->findRsaOffsets();
        if ($rsa) {
            $md5 = $this->findMd5Ranges();
            if ($md5 && $md5[0][0] && $md5[0][1]) {
                $this->doRsa($rsa, $md5);
            } else {
                $this->fail('RSA', 'signature found but no MD5 regions');
            }
        }

        // 5: Main data CRC / checksum
        $crc = array_fill(0, self::MAX_CRC_BLKS + 1, ['start' => 0, 'end' => 0, 'offset' => 0]);
        $this->findMainCrcPreBlk($crc);
        $this->findMainCrcBlks($crc);
        $this->findMainCrcOffsets($crc);
        $csm = $this->findMainCsmOffset();
        if ($crc[1]['start'] && $crc[1]['end'] && ($crc[1]['offset'] || $csm)) {
            if ($crc[1]['offset']) {
                $this->doMainCrcs($crc);
            }
            if ($csm) {
                $this->doMainCsm($crc, $csm);
            }
        } else {
            $this->fail('Main data checksums', 'not found');
        }

        // 6: ROMSYS program pages
        $this->doRomsysProgramPages($romsys);

        // 7: Main program checksum
        $mainOff = $this->findMainProgramOffset();
        $mainFinal = $this->findMainProgramFinal();
        if ($mainOff && $mainFinal) {
            $this->doMainProgramCsm($mainOff, $mainFinal);
        } else {
            $this->fail('Main program checksum', 'not found');
        }

        // 8: Multipoint tables
        for ($i = 0; $i < 2; $i++) {
            $start = $this->findChecksumBlks($i);
            if (! $start) {
                if ($i === 1) {
                    $this->fail('Multipoint table #2', 'not found');
                }

                continue;
            }
            $bootrom = $i === 1 ? $this->checkWhitelist($start) : 0;
            for ($n = 0; $n < self::MAX_MP_BLOCKS; $n++) {
                if ($n > 1) {
                    $bootrom = 0;
                }
                $r = $this->doChecksumBlk($start + self::MP_DESC_LEN * $n, $bootrom, sprintf('Multipoint #%d block %d', $i + 1, $n + 1));
                if ($r === 1) {
                    break;
                }
            }
        }

        // 9: Rechecks
        $this->processRecordDeps();
    }

    // ---------------------------------------------------------------- helpers

    private function u16(int $o): int
    {
        return ord($this->d[$o]) | (ord($this->d[$o + 1]) << 8);
    }

    private function u32(int $o): int
    {
        return unpack('V', $this->d, $o)[1];
    }

    private function put32(int $o, int $v): void
    {
        $this->d = substr_replace($this->d, pack('V', $v & self::M32), $o, 4);
    }

    private static function inv(int $v): int
    {
        return ~$v & self::M32;
    }

    /** Sum of little-endian 16-bit words covering [start,end] (ME7Sum CalcChecksumBlk16). */
    private function sum16(int $start, int $end, ?string $buf = null): int
    {
        $w0 = $start >> 1;
        $w1 = $end >> 1;

        return array_sum(unpack('v*', substr($buf ?? $this->d, $w0 * 2, ($w1 - $w0 + 1) * 2))) & self::M32;
    }

    /** Bytewise sum over [start,end]. */
    private function sum8(int $start, int $end): int
    {
        $sum = 0;
        foreach (count_chars(substr($this->d, $start, $end - $start + 1), 1) as $b => $n) {
            $sum += $b * $n;
        }

        return $sum & self::M32;
    }

    /** Word sum of a block whose own checksum pair lies inside it, with iv pre-corrected to ~v. */
    private function sum16Inside(int $start, int $end, int $csAddr): int
    {
        $tmp = substr_replace($this->d, pack('V', self::inv($this->u32($csAddr))), $csAddr + 4, 4);

        return $this->sum16($start, $end, $tmp);
    }

    private static function crc32Chain(string $data): int
    {
        return crc32($data) & self::M32;
    }

    /** ME7Sum NormalizeRange: CPU address -> file offset. Returns null on error. */
    private function normalize(int $s, int $e): ?array
    {
        if ($s === self::M32 && $e === self::M32) {
            return [$s, $e];
        }
        if ($s < self::BASE || $e < self::BASE) {
            return null;
        }
        $s -= self::BASE;
        $e -= self::BASE;
        if ($s > $e || $s >= $this->len || $e >= $this->len) {
            return null;
        }

        return [$s, $e];
    }

    /**
     * Find every 2-byte-aligned match of needle under mask (ME7Sum search_image loop).
     * A match at p counts only if p + len(needle) + $extra < image length.
     *
     * @return int[]
     */
    private function search(array $needle, ?array $mask, int $from = 0, int $extra = 0): array
    {
        static $cache = [];
        $key = implode(',', $needle).'|'.($mask ? implode(',', $mask) : '');
        if (! isset($cache[$key])) {
            $re = '';
            foreach ($needle as $i => $n) {
                $m = $mask ? $mask[$i] : 0xFF;
                if ($m === 0xFF) {
                    $re .= sprintf('\x%02x', $n);
                } elseif ($m === 0) {
                    $re .= '.';
                } else {
                    $cls = '';
                    for ($b = 0; $b < 256; $b++) {
                        if (($b & $m) === ($n & $m)) {
                            $cls .= sprintf('\x%02x', $b);
                        }
                    }
                    $re .= '['.$cls.']';
                }
            }
            $cache[$key] = '/(?='.$re.')/s';
        }
        $nl = count($needle);
        $out = [];
        $hay = substr($this->d, 0, $this->len);
        if (preg_match_all($cache[$key], $hay, $m, PREG_OFFSET_CAPTURE, $from)) {
            foreach ($m[0] as [, $p]) {
                if (($p & 1) === 0 && $p + $nl + $extra < $this->len) {
                    $out[] = $p;
                }
            }
        }

        return $out;
    }

    private static function bytes(string $hex): array
    {
        return array_map('hexdec', str_split(str_replace(' ', '', $hex), 2));
    }

    /**
     * ME7Sum FindData: locate C167 "mov rX, #lo / mov rY, #hi" address loads.
     *
     * @return array{0:int,1:int[],2:int} [found, offsets (max $max), where (if exactly one)]
     */
    private function findData(string $needle, string $mask, int $offL, int $offH, int $max): array
    {
        $found = 0;
        $offs = [];
        $where = 0;
        foreach ($this->search(self::bytes($needle), self::bytes($mask)) as $i) {
            $low = $this->u16($i + $offL * 2);
            $high = $this->u16($i + $offH * 2);
            $shift = ($high & 0xFE00) ? 14 : 16;   // segment vs. linear high word
            $addr = ($high << $shift) | $low;
            if ($addr > self::BASE && $addr - self::BASE < $this->len) {
                if ($found < $max) {
                    $offs[] = $addr - self::BASE;
                    $where = $i;
                }
                $found++;
            }
        }

        return [$found, $offs, $found === 1 ? $where : 0];
    }

    private function fail(string $name, string $why): void
    {
        $this->uncorrectable++;
        $this->problems[] = "$name: $why";
        $this->blocks[] = ['name' => "$name ($why)", 'start' => 0, 'end' => 0, 'stored' => '', 'calculated' => '', 'ok' => false];
    }

    private function addBlock(string $name, int $start, int $end, string $stored, string $calc, bool $ok): int
    {
        $this->blocks[] = ['name' => $name, 'start' => $start, 'end' => $end, 'stored' => $stored, 'calculated' => $calc, 'ok' => $ok];

        return array_key_last($this->blocks);
    }

    private function publicBlocks(): array
    {
        return array_values(array_map(
            fn ($b) => array_intersect_key($b, array_flip(['name', 'start', 'end', 'stored', 'calculated', 'ok'])),
            $this->blocks
        ));
    }

    /** Count a checksum mismatch; in fix mode apply $write. Returns final ok state. */
    private function mismatch(int $count, callable $write): bool
    {
        $this->errors += $count;
        if ($this->readonly) {
            return false;
        }
        $write();
        $this->corrected += $count;

        return true;
    }

    // ---------------------------------------------------------------- report records (rechecks)

    private function createRecord(string $name, int $start, int $len, ?int $block = null, bool $mp = false): int
    {
        $end = $start + $len - 1;
        // A new checksum stored inside data already covered by an earlier checksum => recheck needed
        foreach ($this->records as &$rr) {
            if ($rr['name'] !== $name) {
                foreach ($rr['data'] as [$ds, $de]) {
                    if (max($start, $ds) <= min($end, $de)) {
                        $rr['dep']++;
                    }
                }
            }
        }
        unset($rr);
        $this->records[] = ['name' => $name, 'cs' => $start, 'data' => [], 'dep' => 0, 'block' => $block, 'mp' => $mp];

        return array_key_last($this->records);
    }

    private function addRange(int $rec, int $start, int $end): void
    {
        $this->records[$rec]['data'][] = [$start, $end];
    }

    private function processRecordDeps(): void
    {
        foreach ($this->records as $rr) {
            if (! $rr['dep']) {
                continue;
            }
            if (! $rr['mp']) {
                // ME7Sum has no recheck callback for these; it reports an error
                $this->errors++;
                if ($rr['block'] !== null) {
                    $this->blocks[$rr['block']]['ok'] = false;
                    $this->blocks[$rr['block']]['name'] .= ' (recheck unsupported)';
                }

                continue;
            }
            // MP_callback: recompute multipoint sum after later checksums were written
            $calc = 0;
            foreach ($rr['data'] as [$s, $e]) {
                $calc = ($calc + $this->sum16($s, $e)) & self::M32;
            }
            $v = $this->u32($rr['cs']);
            $b = &$this->blocks[$rr['block']];
            $b['calculated'] = sprintf('%08X', $calc);
            if ($v !== $calc) {
                $cs = $rr['cs'];
                $b['ok'] = $this->mismatch(1, function () use ($cs, $calc) {
                    $this->put32($cs, $calc);
                    $this->put32($cs + 4, self::inv($calc));
                });
            }
            unset($b);
        }
    }

    // ---------------------------------------------------------------- ROM info

    private function findEcuId(): bool
    {
        [$found, $offs] = $this->findData(
            'E6F40000E6F50602F6F442E2F6F544E2',
            'FFFF0000FFFFF0FFFFFFF0FFFFFFF0FF',
            1, 3, 2
        );
        if ($found < 1) {
            return false;
        }
        $d0 = $offs[0];
        if ($this->infoItem($d0 + 2 * 6) === null && $found > 1) {
            $d0 = $offs[1];
        }

        return $this->infoItem($d0 + 10 * 6) !== null;   // part number descriptor
    }

    /** string_desc {u8 tag, u8 len, u16 ptr, u16 seg} -> [off,len] or null. */
    private function infoItem(int $o): ?array
    {
        if ($o + 6 > $this->len || ord($this->d[$o]) !== 6) {
            return null;
        }
        $len = ord($this->d[$o + 1]);
        $addr = ($this->u16($o + 4) << 14) | $this->u16($o + 2);
        if ($addr > self::BASE && $addr + $len < self::BASE + $this->len) {
            return [$addr - self::BASE, $len];
        }

        return null;
    }

    // ---------------------------------------------------------------- ROMSYS

    private function doRomsys(int $romsys): void
    {
        if ($this->u16(0) === 0) {
            $this->fail('ROMSYS', 'first word is zero, corrupted bin?');
        }

        // Startup: word[0x8000]+word[0xFFFE] stored at ROMSYS+0x38
        $off = $romsys + 0x38;
        $rec = $this->createRecord('ROMSYS Startup', $off, 3);
        $this->addRange($rec, 0x8000, 0x8001);
        $this->addRange($rec, 0xFFFE, 0xFFFF);
        $calc = $this->u16(0x8000) + $this->u16(0xFFFE);
        $stored = $this->u32($off);
        $this->found++;
        $ok = $calc === $stored || $this->mismatch(1, function () use ($off, $calc) {
            // ME7Sum writes only the low 16 bits
            $this->d = substr_replace($this->d, pack('v', $calc & 0xFFFF), $off, 2);
        });
        $this->addBlock('ROMSYS startup', 0x8000, 0xFFFF, sprintf('%08X', $stored), sprintf('%08X', $calc), $ok);

        // All param page: first+last word of the param page, sum stored at pointer ROMSYS+0x10
        $p = $this->u32($romsys + 0x10);
        if ($p < self::BASE || $p - self::BASE >= $this->len) {
            $this->fail('ROMSYS param page', sprintf('invalid address 0x%X', $p));

            return;
        }
        $off = $p - self::BASE;
        $r = $this->normalize($this->u32($romsys + 0x30), $this->u32($romsys + 0x34));
        if ($r === null || $off + 4 > $this->len) {
            $this->fail('ROMSYS param page', 'invalid range');

            return;
        }
        $rec = $this->createRecord('ROMSYS ParamPage', $off, 4);
        $this->addRange($rec, $r[0], $r[0] + 1);
        $this->addRange($rec, $r[1], $r[1] + 1);
        $stored = $this->u32($off);
        $calc = $this->u16($r[0]) + $this->u16($r[1]);
        $this->found++;
        $ok = $calc === $stored || $this->mismatch(1, fn () => $this->put32($off, $calc));
        $this->addBlock('ROMSYS param page', $r[0], $r[1] + 1, sprintf('%08X', $stored), sprintf('%08X', $calc), $ok);
    }

    private function doRomsysProgramPages(int $romsys): void
    {
        $off = $romsys + 0x3C;
        $rec = $this->createRecord('ROMSYS ProgramPages', $off, 4);
        $calc = 0;
        // first+last word of every 8k page in 0x0000-0xFFFF and 0x20000-end
        foreach ([[0, 0xFFFF], [0x20000, $this->len - 1]] as [$s, $e]) {
            for ($a = $s; $a < $e; $a += 0x2000) {
                $this->addRange($rec, $a, $a + 1);
                $this->addRange($rec, $a + 0x1FFE, $a + 0x1FFF);
                $calc += $this->u16($a) + $this->u16($a + 0x1FFE);
            }
        }
        $calc &= self::M32;
        $stored = $this->u32($off);
        $this->found++;
        $ok = $calc === $stored || $this->mismatch(1, fn () => $this->put32($off, $calc));
        $this->addBlock('ROMSYS program pages', 0, $this->len - 1, sprintf('%08X', $stored), sprintf('%08X', $calc), $ok);
    }

    // ---------------------------------------------------------------- CRC table

    /** @return int[] offsets of embedded CRC32 tables */
    private function findCrcTab(): array
    {
        [$found, $offs] = $this->findData('E6F40000E6F58000A960C0625C22', 'FFFF0100FFFFFCFFFFFFFFFFFFFF', 1, 3, 2);
        if ($found < 1 || $found > 2) {
            [$found, $offs] = $this->findData('E6F40000E6F58000C0C25C22', 'FFFF0100FFFFFCFFFFFFFFFF', 1, 3, 2);
        }
        if ($found < 1 || $found > 2) {
            return [];   // ME7Sum's fallback search never sets the table offsets either
        }
        $tabs = [];
        foreach ($offs as $o) {
            if ($this->u32($o) === 0) {   // CRC table always starts with 0
                $tabs[] = $o;
            }
        }

        return ($tabs && count($tabs) <= 2) ? $tabs : [];
    }

    private static function crcTable(): string
    {
        static $t = null;
        if ($t === null) {
            $t = '';
            for ($i = 0; $i < 256; $i++) {
                $c = $i;
                for ($k = 0; $k < 8; $k++) {
                    $c = ($c & 1) ? (0xEDB88320 ^ ($c >> 1)) : ($c >> 1);
                }
                $t .= pack('V', $c);
            }
        }

        return $t;
    }

    private function doCrcTab(array $tabs): void
    {
        $ref = self::crcTable();
        foreach ($tabs as $i => $o) {
            $good = substr($this->d, $o, 1024) === $ref;
            $ok = $good || $this->mismatch(1, function () use ($o, $ref) {
                // reverts a tuner-modified CRC table, as ME7Sum does
                $this->d = substr_replace($this->d, $ref, $o, 1024);
            });
            $this->addBlock('CRC table #'.$i, $o, $o + 1023, $good ? 'standard' : 'modified', 'standard', $ok);
            if (! $ok) {
                return;   // ME7Sum stops at the first bad table in check mode
            }
        }
    }

    // ---------------------------------------------------------------- RSA

    /** @return array{s:int,n:int,e:int}|null */
    private function findRsaOffsets(): ?array
    {
        $mask = 'FFFFFFFFFFFF0000FFFFF0FFFFFF';
        $needles = ['80008840E6F40000E6F580008850', 'E0448840E6F40000E6F580008850'];
        $s = $n = $e = 0;
        foreach ($needles as $i => $needle) {
            [$found, $offs] = $this->findData($needle, $mask, 3, 5, 2);
            $want = $i ? 1 : 2;
            if ($found === $want) {
                if ($i === 0) {
                    [$s, $n] = $offs;
                } else {
                    $e = $offs[0];
                }
            } elseif ($found < $want) {
                return null;   // missing
            }
        }
        if ($s + self::RSA_BLOCK >= $this->len || $n + self::RSA_BLOCK >= $this->len || $e + 4 + self::RSA_BLOCK >= $this->len) {
            return null;
        }
        if (unpack('N', $this->d, $e)[1] !== 3 || ! $s || ! $n || ! $e) {
            return null;
        }

        return ['s' => $s, 'n' => $n, 'e' => $e];
    }

    /** @return array<int,array{0:int,1:int}>|null MD5 ranges (file offsets) */
    private function findMd5Ranges(): ?array
    {
        $hits = $this->search(
            self::bytes('E108F7F800F0F2F40000F2F50000F6F4'),
            self::bytes('FFCF000000F0FFFF0000FFFF000000FF'),
            0, 2
        );
        if (count($hits) !== 1) {
            return null;
        }
        $a = $hits[0];
        $count = ((ord($this->d[$a + 1]) & 0xF0) >> 4) + 1;
        $table = $this->u16($a + 8) | 0x10000;
        if ($count < 1 || $count > 4 || $table + 32 > $this->len) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $r = $this->normalize($this->u32($table + $i * 4), $this->u32($table + 16 + $i * 4));
            if ($r === null) {
                return null;
            }
            $out[] = $r;
        }

        return $out;
    }

    private function doRsa(array $rsa, array $md5): void
    {
        $recN = $this->createRecord('RSA mod', $rsa['n'], self::RSA_BLOCK);
        $recS = $this->createRecord('RSA sig', $rsa['s'], self::RSA_BLOCK);
        $this->found++;
        $data = '';
        foreach ($md5 as [$s, $e]) {
            $this->addRange($recN, $s, $e);
            $this->addRange($recS, $s, $e);
            $data .= substr($this->d, $s, $e - $s + 1);
        }
        $calc = md5($data);

        if (! extension_loaded('gmp')) {
            // cannot verify without gmp: detection only, and never write a possibly unsigned file
            if (! $this->readonly) {
                $this->fail('RSA signature', 'gmp extension required to verify/re-sign');

                return;
            }
            $this->addBlock('RSA signature (not verified)', $rsa['s'], $rsa['s'] + self::RSA_BLOCK - 1, 'n/a', $calc, true);

            return;
        }

        // signature^3 mod n -> PKCS#1 type 1 padded MD5
        $n = gmp_import(substr($this->d, $rsa['n'], self::RSA_BLOCK));
        $sig = gmp_import(substr($this->d, $rsa['s'], self::RSA_BLOCK));
        $blk = gmp_cmp($n, 0) > 0 ? gmp_export(gmp_powm($sig, 3, $n)) : '';
        $blk = str_pad($blk, self::RSA_BLOCK, "\0", STR_PAD_LEFT);
        $stored = str_repeat("\0", 16);
        if ($blk[0] === "\0" && $blk[1] === "\1") {
            $i = 2;
            while ($blk[$i] !== "\0" && $i < self::RSA_BLOCK - 16) {
                $i++;
            }
            if ($i + 1 < 11 || $blk[$i] !== "\0") {
                $this->fail('RSA signature', 'bad padding');

                return;
            }
            $stored = substr($blk, $i + 1, 16);
        }
        $stored = bin2hex($stored);
        $ok = $stored === $calc || $this->mismatch(1, fn () => $this->rsaSign($rsa, $md5));
        $this->addBlock('RSA signature', $rsa['s'], $rsa['s'] + self::RSA_BLOCK - 1, $stored, $calc, $ok);
    }

    /**
     * ME7Sum RSASign: replace modulus with a freshly generated e=3 key and sign the MD5.
     * The ECU verifies with the modulus stored in flash, so any valid key works.
     * Output is random (like ME7Sum), so it is never byte-identical between runs.
     */
    private function rsaSign(array $rsa, array $md5): void
    {
        $half = self::RSA_BLOCK / 2;
        $prime = function () use ($half): \GMP {
            $buf = random_bytes($half);
            $buf[0] = chr(ord($buf[0]) | 0xC0);   // keep n at full 1024 bits
            $buf[$half - 1] = chr(ord($buf[$half - 1]) | 0x01);
            $p = gmp_nextprime(gmp_import($buf));
            while (gmp_intval(gmp_mod($p, 3)) === 1) {   // need gcd(3, p-1) = 1
                $p = gmp_nextprime($p);
            }

            return $p;
        };
        do {
            $p = $prime();
            $q = $prime();
        } while (gmp_cmp($p, $q) === 0);
        $n = gmp_mul($p, $q);
        $d = gmp_invert(3, gmp_mul(gmp_sub($p, 1), gmp_sub($q, 1)));
        if ($d === false) {
            throw new RuntimeException('RSA key generation failed');
        }
        $this->d = substr_replace($this->d, str_pad(gmp_export($n), self::RSA_BLOCK, "\0", STR_PAD_LEFT), $rsa['n'], self::RSA_BLOCK);

        // MD5 over the (possibly already corrected) ranges, PKCS#1 type 1 padded
        $data = '';
        foreach ($md5 as [$s, $e]) {
            $data .= substr($this->d, $s, $e - $s + 1);
        }
        $msg = "\0\1".str_repeat("\xFF", self::RSA_BLOCK - 3 - 16)."\0".md5($data, true);
        $sig = gmp_powm(gmp_import($msg), $d, $n);
        if (gmp_export(gmp_powm($sig, 3, $n)) !== substr($msg, 1)) {
            throw new RuntimeException('RSA signature self-check failed');
        }
        $this->d = substr_replace($this->d, str_pad(gmp_export($sig), self::RSA_BLOCK, "\0", STR_PAD_LEFT), $rsa['s'], self::RSA_BLOCK);
    }

    // ---------------------------------------------------------------- main data CRC / checksum

    private function findMainCrcPreBlk(array &$crc): void
    {
        [$found, $offs, $where] = $this->findData('E6FC0000E6FD8000E00EDA000000F6F4', 'FFFF0000FFFFF0FFFF0FFF000000FFFF', 1, 3, 1);
        if ($found === 1) {
            $crc[0]['start'] = $offs[0];
            $crc[0]['end'] = $offs[0] + (ord($this->d[$where + 9]) >> 4) - 1;
        }
    }

    private function findMainCrcBlks(array &$crc): void
    {
        $ok0 = $ok1 = false;
        [$found, $offs] = $this->findData('E6F80000E6F98000F2F4', 'FFFF0000FFFFF0FFFFFF', 1, 3, self::MAX_CRC_BLKS);
        if ($found > 0 && $found <= self::MAX_CRC_BLKS) {
            foreach ($offs as $i => $o) {
                $crc[$i + 1]['start'] = $o;
            }
            $ok0 = true;
        }
        [$found, $offs] = $this->findData('109BE6F40000E6F58000', 'FFFFFFFF0000FFFFF0FF', 2, 4, self::MAX_CRC_BLKS);
        if ($found > 0 && $found <= self::MAX_CRC_BLKS) {
            foreach ($offs as $i => $o) {
                $crc[$i + 1]['end'] = $o;
            }
            $ok1 = true;
        }
        if ((! $ok0 || ! $ok1) && ($this->len === 0x80000 || $this->pad === 'try512')) {
            // default 512k CRC blocks
            $crc[1]['start'] = 0x10000;
            $crc[1]['end'] = 0x13FFF;
            $crc[2]['start'] = 0x14300;
            $crc[2]['end'] = 0x17F67;
            $crc[3]['start'] = 0x18191;
            $crc[3]['end'] = 0x1FBFF;
        }
    }

    private function findMainCrcOffsets(array &$crc): void
    {
        [$found, $offs] = $this->findData('F6F50000E6F40000E6F58000DA00', 'FFFF0000FFFF0100FFFFF0FFFFFF', 3, 5, self::MAX_CRC_BLKS);
        if ($found > 0 && $found <= self::MAX_CRC_BLKS) {
            foreach ($offs as $i => $o) {
                $crc[$i + 1]['offset'] = $o;
            }
        }
        if ($found !== 3) {
            for ($i = 1; $i < self::MAX_CRC_BLKS; $i++) {
                $crc[$i]['offset'] = 0;
            }

            return;
        }
        // exactly 3 CRCs and a 4th region: region 3's CRC slot is used for 3+4
        if ($crc[4]['start'] && $crc[4]['end']) {
            $crc[4]['offset'] = $crc[3]['offset'];
            $crc[3]['offset'] = 0;
        }
    }

    private function findMainCsmOffset(): int
    {
        $mask = 'FFFFFFFF0100FFFFF0FFFFFF';
        [$found, $offs] = $this->findData('E10CE6F40000E6F58000DA00', $mask, 2, 4, 1);
        if ($found < 1) {
            [$found, $offs] = $this->findData('0400E6F40000E6F58000DA00', $mask, 2, 4, 1);

            return $found === 1 ? $offs[0] : 0;
        }

        return $found === 1 ? $offs[0] : 0;
    }

    private function doMainCrcs(array $crc): void
    {
        $chain = '';   // CRC continues across regions only when a pre-block exists
        $hasPre = $crc[0]['start'] && $crc[0]['end'];
        for ($i = 0; $i <= self::MAX_CRC_BLKS; $i++) {
            $s = $crc[$i]['start'];
            $e = $crc[$i]['end'];
            if (! $s || ! $e) {
                continue;
            }
            if ($e < $s || $e >= $this->len) {
                $this->fail("Main data CRC $i", 'invalid range');

                return;
            }
            $data = substr($this->d, $s, $e - $s + 1);
            $calc = self::crc32Chain($chain.$data);
            $at = $crc[$i]['offset'];
            if ($at && $at + 4 <= $this->len) {
                $rec = $this->createRecord('Main CRC', $at, 3);
                $this->addRange($rec, $s, $e);
                $stored = $this->u32($at);
                $this->found++;
                $ok = $calc === $stored || $this->mismatch(1, fn () => $this->put32($at, $calc));
                $this->addBlock("Main data CRC $i", $s, $e, sprintf('%08X', $stored), sprintf('%08X', $calc), $ok);
            }
            if ($hasPre) {
                $chain .= $data;
            }
        }
    }

    private function doMainCsm(array $crc, int $at): void
    {
        if ($at + 8 > $this->len) {
            $this->fail('Main data checksum', 'invalid address');

            return;
        }
        $v = $this->u32($at);
        $iv = $this->u32($at + 4);
        $rec = $this->createRecord('Main Checksums', $at, 3);
        $calc = 0;
        $lo = PHP_INT_MAX;
        $hi = 0;
        for ($i = 1; $i <= self::MAX_CRC_BLKS; $i++) {
            $s = $crc[$i]['start'];
            $e = $crc[$i]['end'];
            if ($s && $e) {
                if ($e < $s || $e >= $this->len) {
                    $this->fail('Main data checksum', 'invalid range');

                    return;
                }
                $this->addRange($rec, $s, $e);
                $calc = ($calc + $this->sum8($s, $e)) & self::M32;
                $lo = min($lo, $s);
                $hi = max($hi, $e);
            }
        }
        $this->found++;
        if ($calc !== $v) {
            $ok = $this->mismatch(1, function () use ($at, $calc) {
                $this->put32($at, $calc);
                $this->put32($at + 4, self::inv($calc));
            });
        } elseif ($v !== self::inv($iv)) {
            $ok = $this->mismatch(1, fn () => $this->put32($at + 4, self::inv($v)));
        } else {
            $ok = true;
        }
        $this->addBlock('Main data checksum', $lo, $hi, sprintf('%08X', $v), sprintf('%08X', $calc), $ok);
    }

    // ---------------------------------------------------------------- main program checksum

    private function findMainProgramOffset(): int
    {
        $needle = pack('V4', self::BASE, self::BASE + 0x0FBFF, self::BASE + 0x20000, self::BASE + 0xF00FF);
        $mask = pack('V4', self::M32, self::M32, self::M32, 0xFFF700FF);
        $hits = $this->search(array_values(unpack('C*', $needle)), array_values(unpack('C*', $mask)));

        return count($hits) === 1 ? $hits[0] : 0;
    }

    private function findMainProgramFinal(): int
    {
        $o = $this->len - 0x20;

        return $this->u32($o) === self::inv($this->u32($o + 4)) ? $o : 0;
    }

    private function doMainProgramCsm(int $descAt, int $at): void
    {
        $rec = $this->createRecord('Main Program Checksum', $at, 8);
        $r0 = $this->normalize($this->u32($descAt), $this->u32($descAt + 4));
        $r1 = $this->normalize($this->u32($descAt + 8), $this->u32($descAt + 12));
        if ($r0 === null || $r1 === null || $r0[0] === self::M32 || $r1[0] === self::M32) {
            $this->fail('Main program checksum', 'bad descriptor');

            return;
        }
        $calc = $this->sum16($r0[0], $r0[1]);
        $v = $this->u32($at);
        $iv = $this->u32($at + 4);
        $inside = $at + 8 >= $r1[0] && $at <= $r1[1];
        $this->addRange($rec, $r1[0], $r1[1]);
        $calc2 = ($inside && $iv !== self::inv($v)) ? $this->sum16Inside($r1[0], $r1[1], $at) : $this->sum16($r1[0], $r1[1]);
        $calc = ($calc + $calc2) & self::M32;
        $this->found++;
        $errs = ($v !== $calc ? 1 : 0) + ($iv !== self::inv($v) ? 1 : 0);
        $ok = ! $errs || $this->mismatch($errs, function () use ($at, $calc) {
            $this->put32($at, $calc);
            $this->put32($at + 4, self::inv($calc));
        });
        $this->addBlock('Main program checksum', $r0[0], $r1[1], sprintf('%08X', $v), sprintf('%08X', $calc), $ok);
    }

    // ---------------------------------------------------------------- multipoint

    /** which=0: optional table #1, which=1: main table #2. Returns file offset or 0. */
    private function findChecksumBlks(int $which): int
    {
        if ($which === 0) {
            $needle = pack('V', self::BASE + 0x24000);
            $end1 = self::BASE + 0x27FFF;
        } else {
            $needle = pack('V2', 0, 0x3FFF);
        }
        $found = 0;
        $offset = 0;
        foreach ($this->search(array_values(unpack('C*', $needle)), null, 0x10000) as $i) {
            if ($i + self::MP_DESC_LEN >= $this->len) {
                continue;
            }
            if ($which === 0) {
                if ($this->u32($i + 4) !== $end1) {   // that one belongs to table #2
                    $offset = $i;
                    $found++;
                }
            } elseif ($this->u32($i + 8) === self::inv($this->u32($i + 12))) {
                $offset = $i;
                $found++;
            }
        }
        if ($found !== 1) {
            return 0;
        }
        $next = $offset + self::MP_DESC_LEN;
        if ($which === 1 && $this->u32($next + 8) !== self::inv($this->u32($next + 12))) {
            return 0;
        }

        return $offset;
    }

    /** Boot ROM descriptors: 0 = none, 1 = present (maybe whitelisted), -1 = inconsistent. */
    private function checkWhitelist(int $at): int
    {
        $d = [];
        for ($i = 0; $i < 4; $i++) {
            $o = $at + self::MP_DESC_LEN * $i;
            $d[$i] = [$this->u32($o), $this->u32($o + 4), $this->u32($o + 8), $this->u32($o + 12)];
        }
        for ($i = 0; $i < 2; $i++) {
            if ($d[$i][0] >= $d[$i][1] || $d[$i][0] >= self::BASE || $d[$i][1] >= self::BASE) {
                return 0;
            }
        }
        if ($d[0][0] !== 0 || $d[0][1] !== 0x3FFF || $d[1][0] !== 0x4000 || $d[1][1] !== 0x7FFF) {
            return 0;
        }
        foreach ([[0x0FA0F5CF, 0x0F4716B3], [0x0E59D5C8, 0x1077FB35]] as [$w0, $w1]) {
            if ($d[0][2] === self::inv($d[0][3]) && $d[1][2] === self::inv($d[1][3]) && $d[0][2] === $w0 && $d[1][2] === $w1) {
                $this->whitelisted = true;

                return 1;
            }
        }
        if ($d[0][2] !== $d[2][2] || $d[1][2] !== $d[3][2] || $d[0][3] !== $d[2][3] || $d[1][3] !== $d[3][3]) {
            $this->fail('Multipoint boot ROM', 'inconsistent non-whitelisted descriptors');

            return -1;
        }

        return 1;
    }

    /** ME7Sum DoChecksumBlk. Returns 1 at the end marker, <0 on error, 0 otherwise. */
    private function doChecksumBlk(int $at, int $bootrom, string $name): int
    {
        if ($at + 16 >= $this->len) {
            $this->fail($name, 'descriptor past end of file');

            return -1;
        }
        $cs = $at + 8;
        $s = $this->u32($at);
        $e = $this->u32($at + 4);
        $v = $this->u32($cs);
        $iv = $this->u32($cs + 4);
        if (! $bootrom) {
            $r = $this->normalize($s, $e);
            if ($r === null) {
                $this->fail($name, sprintf('invalid range 0x%X-0x%X', $s, $e));

                return -1;
            }
            [$s, $e] = $r;
        }
        if ($s === self::M32) {
            return 1;
        }
        $inside = $cs + 8 >= $s && $cs <= $e;
        $errs = 0;
        $rec = null;
        if ($bootrom && $this->whitelisted) {
            $calc = $v;   // boot ROM is not in the dump; known-good sums are whitelisted
            $name .= ' (boot ROM, whitelisted)';
        } else {
            $rec = $this->createRecord('MP Block', $cs, 8, null, true);
            $this->addRange($rec, $s, $e);
            $calc = ($inside && $iv !== self::inv($v)) ? $this->sum16Inside($s, $e, $cs) : $this->sum16($s, $e);
            $this->found++;
            if ($v !== $calc) {
                $errs++;
            }
        }
        if ($iv !== self::inv($v)) {
            $errs++;
        }
        $ok = ! $errs || $this->mismatch($errs, function () use ($cs, $calc) {
            $this->put32($cs, $calc);
            $this->put32($cs + 4, self::inv($calc));
        });
        $idx = $this->addBlock($name, $s, $e, sprintf('%08X', $v), sprintf('%08X', $calc), $ok);
        if ($rec !== null) {
            $this->records[$rec]['block'] = $idx;
        }
        if (! $ok) {
            return -1;
        }

        return $bootrom ? -2 : 0;
    }
}
