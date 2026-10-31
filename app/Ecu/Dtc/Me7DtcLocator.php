<?php

namespace App\Ecu\Dtc;

use App\Ecu\Bin;
use App\Ecu\Families\Me7\C167;

/**
 * Bosch ME7 (ME7.1 / 7.1.1 / 7.5, VAG, C167, flash mapped at 0x800000) fault-path tables.
 *
 * Everything is found from the fault manager's own code, not from fixed addresses:
 *
 *  - Code-set selector: a small routine reads a codeword byte and stores one of two pointers in a RAM
 *    variable: "extp #page,#1 / movb rl4,cw / cmpb rl4,#1 / jmpr nz / mov r4,#set1 [/ mov r5,#page]
 *    / mov var,r4 / rets / mov r4,#set2 ...". Both sets are N fault paths x 4 words (fault types
 *    max, min, signal, plausibility; P-codes as hex digits, 0x0301 = P0301). The two sets lie back
 *    to back, so N = (set2 - set1) / 8. Set 1 is the VAG/manufacturer set (mostly P1xxx), which the
 *    VAG 5-digit code conversion always reads; set 2 is the generic SAE set. The fault memory /
 *    scan-tool code reads whichever set the pointer holds (set 1 when the codeword equals the compare
 *    value, set 2 otherwise).
 *  - Error class ("Fehlerklasse") per path: the fault-report routine is the most-called function with
 *    the prologue "mov [-r0],r6 / mov r6,r12" (r12 = path number, status word on the stack). The
 *    first routine it calls is the "enter fault" routine, which begins with
 *    "movbz r4,rl4 / [extp] / movb rlX,[r4+#class] / ... / jmp cc_Z exit": a class of 0 makes it
 *    return before a fault-memory entry is created, so the path is never stored and never requests
 *    the MIL (the MIL request is derived from the stored entries only).
 *  - Class records: the same routine indexes a table of 14-byte records by class ("mov rA,#rec /
 *    mov rB,#page / x*14"). Byte 0 of a record decides whether stored entries of that class request
 *    the MIL (0 = no, 1 = on, 2 = on + second lamp-status bit).
 */
class Me7DtcLocator implements DtcLocator
{
    private const FLASH_BASE = 0x800000;

    private const CLASS_RECORD_SIZE = 14;

    /** @var string[] fault-type order of the four words of a path */
    private const SLOTS = ['max', 'min', 'signal', 'plausibility'];

    /** @var int[] */
    private array $dpp = [0x204, 0x205, 0xE0, 0x03];

    public function locate(Bin $bin): ?DtcTable
    {
        if ($bin->size < 0x40000) {
            return null;
        }
        $this->dpp = C167::dpps($bin);

        $selector = $this->findSelector($bin);
        if ($selector === null) {
            return null;
        }
        $count = $selector['count'];
        $class = $this->findClassTable($bin, $count);

        $notes = [
            sprintf('%d fault paths. P-code set 1 (VAG/manufacturer) at 0x%X-0x%X, set 2 (generic SAE) at 0x%X-0x%X, 4 words per path (max, min, signal, plausibility).',
                $count, $selector['set1'], $selector['set1'] + $count * 8 - 1, $selector['set2'], $selector['set2'] + $count * 8 - 1),
            sprintf('Code-set selector at 0x%X: codeword at 0x%X = %d, so the fault-memory/scan-tool code uses set %d. The VAG 5-digit code conversion always reads set 1.',
                $selector['at'], $selector['codeword'], $selector['codewordValue'], $selector['active']),
        ];
        $fieldHelp = [];

        if ($class !== null) {
            $notes[] = sprintf('Error-class table at 0x%X (1 byte per path), read by the enter-fault routine at 0x%X: class 0 returns before a fault-memory entry is made (high confidence).',
                $class['table'], $class['enter']);
            if ($class['records'] !== null) {
                $notes[] = sprintf('Class records at 0x%X (%d bytes each). Byte 0 = MIL request of stored entries of that class; MIL classes in use: %s (medium confidence on the lamp meaning).',
                    $class['records'], self::CLASS_RECORD_SIZE, $this->milSummary($bin, $class, $count));
            }
            $fieldHelp['class'] = 'Error class (Fehlerklasse): picks the debounce/healing/MIL behaviour record for this path. 0 = the path is never stored in fault memory and never lights the MIL.';
        } else {
            $notes[] = 'Error-class table not found in this software; only the P-codes are shown.';
        }
        $notes[] = 'Changing a path\'s class does not stop the diagnosis itself; the monitored function keeps running and can still react (limp mode, substitute values).';

        $paths = [];
        for ($p = 0; $p < $count; $p++) {
            $codes = [];
            foreach ([1 => $selector['set1'], 2 => $selector['set2']] as $set => $base) {
                foreach (self::SLOTS as $i => $slot) {
                    $address = $base + $p * 8 + $i * 2;
                    $codes[] = ['slot' => ($set === 1 ? 'VAG ' : 'SAE ').$slot, 'address' => $address, 'code' => $bin->u16($address)];
                }
            }
            if (! array_filter(array_column($codes, 'code'))) {
                continue;
            }
            $fields = [];
            if ($class !== null) {
                $address = $class['table'] + $p;
                $value = $bin->u8($address);
                $fields[] = [
                    'key' => 'class',
                    'label' => 'Error class'.$this->milLabel($bin, $class, $value),
                    'address' => $address,
                    'size' => 1,
                    'value' => $value,
                    'offValue' => 0,
                ];
            }
            $paths[] = new FaultPath($p, $codes, $fields);
        }

        return $paths ? new DtcTable($paths, 'pcode-hex', $notes, $fieldHelp, 2, false) : null;
    }

    /**
     * The routine that points the fault manager at one of the two P-code sets.
     *
     * @return array{at: int, set1: int, set2: int, count: int, codeword: int, codewordValue: int, active: int}|null
     */
    private function findSelector(Bin $bin): ?array
    {
        $head = '\xD7[\x40-\x70](..)\xF3[\xF0-\xFF](..)\x49(.)\x3D.';
        $forms = [
            // near pointers: mov r4,#set / mov var,r4 / rets
            'near' => '/'.$head.'\xE6([\xF0-\xFF])(..)\xF6\4(..)\xDB\x00\xE6\4(..)\xF6\4\6\xDB\x00/s',
            // far pointers: mov r4,#set / mov r5,#page / mov var,r4 / mov var+2,r5 / rets
            'far' => '/'.$head.'\xE6([\xF0-\xFF])(..)\xE6([\xF0-\xFF])(..)\xF6\4..\xF6\6..\xDB\x00\xE6\4(..)\xE6\6(..)/s',
        ];
        foreach ($forms as $form => $regex) {
            if (! preg_match_all($regex, $bin->data, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($all as $m) {
                $at = $m[0][1];
                if ($at % 2) {
                    continue;
                }
                $word = fn (int $g): int => unpack('v', $m[$g][0])[1];
                if ($form === 'near') {
                    $set1 = $this->near($word(5));
                    $set2 = $this->near($word(7));
                } else {
                    $set1 = $this->far($word(5), $word(7));
                    $set2 = $this->far($word(8), $word(9));
                }
                $codeword = $this->far($word(2), $word(1));
                if ($set1 === null || $set2 === null || $codeword === null || $set2 <= $set1 || ($set2 - $set1) % 8) {
                    continue;
                }
                $count = intdiv($set2 - $set1, 8);
                if ($count < 16 || $count > 255 || $set2 + $count * 8 > $bin->size
                    || ! $this->looksLikeCodes($bin, $set1, $count) || ! $this->looksLikeCodes($bin, $set2, $count)) {
                    continue;
                }
                $compare = ord($m[3][0]) & 0x07;
                $value = $bin->u8($codeword);

                return [
                    'at' => $at, 'set1' => $set1, 'set2' => $set2, 'count' => $count,
                    'codeword' => $codeword, 'codewordValue' => $value, 'active' => $value === $compare ? 1 : 2,
                ];
            }
        }

        return null;
    }

    /** Path 0 is unused (all zero) and nearly all words are hex-digit P-codes. */
    private function looksLikeCodes(Bin $bin, int $base, int $count): bool
    {
        if ($bin->slice($base, 8) !== str_repeat("\0", 8)) {
            return false;
        }
        $valid = $nonZero = 0;
        for ($o = $base; $o < $base + $count * 8; $o += 2) {
            $w = $bin->u16($o);
            if ($w === 0) {
                continue;
            }
            $nonZero++;
            $valid += ($w >> 12) <= 3 && (($w >> 8) & 0xF) <= 9 && (($w >> 4) & 0xF) <= 9 && ($w & 0xF) <= 9 ? 1 : 0;
        }

        return $nonZero >= $count && $valid >= $nonZero * 0.9;
    }

    /**
     * Fault-report routine -> enter-fault routine -> class table (and class records).
     *
     * @return array{table: int, enter: int, records: ?int}|null
     */
    private function findClassTable(Bin $bin, int $count): ?array
    {
        foreach ($this->reportRoutines($bin) as $report) {
            $enter = $this->firstCall($bin, $report, 0x40);
            if ($enter === null) {
                continue;
            }
            $window = $bin->slice($enter, 0x50);
            if (! preg_match('/\xC0\x84(?:\xD7[\x40-\x70](..))?\xF4(.)(..)(.{0,12}?)(?:\xEA\x20|\x2D)/s', $window, $m, PREG_OFFSET_CAPTURE)
                || (ord($m[2][0]) & 0x0F) !== 4) {
                continue;
            }
            $pointer = unpack('v', $m[3][0])[1];
            $table = $m[1][0] !== '' ? $this->far($pointer, unpack('v', $m[1][0])[1]) : $this->near($pointer);
            if ($table === null || $table + $count > $bin->size) {
                continue;
            }
            $values = array_values(unpack('C*', $bin->slice($table, $count)));
            if (max($values) >= 0x40 || count(array_filter($values)) < $count / 4) {
                continue;
            }

            return ['table' => $table, 'enter' => $enter, 'records' => $this->classRecords($bin, $enter)];
        }

        return null;
    }

    /**
     * Functions starting with "mov [-r0],r6 / mov r6,r12", most-called first.
     *
     * @return int[]
     */
    private function reportRoutines(Bin $bin): array
    {
        $calls = [];
        if (preg_match_all('/(?=\xDA([\x80-\x8F])(..))/s', $bin->data, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                if ($m[0][1] % 2 === 0) {
                    $target = ((ord($m[1][0]) - 0x80) << 16) | unpack('v', $m[2][0])[1];
                    $calls[$target] = ($calls[$target] ?? 0) + 1;
                }
            }
        }
        arsort($calls);
        $out = [];
        foreach ($calls as $target => $n) {
            if ($n < 20 || count($out) >= 3) {
                break;
            }
            if ($target + 8 < $bin->size && $bin->slice($target, 4) === "\x88\x60\xF0\x6C") {
                $out[] = $target;
            }
        }

        return $out;
    }

    private function firstCall(Bin $bin, int $from, int $length): ?int
    {
        for ($o = $from; $o < $from + $length && $o + 4 <= $bin->size; $o += 2) {
            $op = $bin->u8($o);
            if ($op === 0xDA && ($bin->u8($o + 1) & 0xF0) === 0x80) {
                $target = (($bin->u8($o + 1) - 0x80) << 16) | $bin->u16($o + 2);

                return $target < $bin->size ? $target : null;
            }
            $o += C167::len($op) - 2;
        }

        return null;
    }

    /** "mov rA,#records / mov rB,#page" followed by the x*14 index arithmetic. */
    private function classRecords(Bin $bin, int $enter): ?int
    {
        $code = $bin->slice($enter, 0x200);
        if (preg_match_all('/\xE6[\xF0-\xFF](..)\xE6[\xF0-\xFF](..)/s', $code, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($all as $m) {
                $page = unpack('v', $m[2][0])[1];
                $after = substr($code, $m[0][1] + 8, 16);
                if ($page >= 0x200 && $page < 0x240 && preg_match('/\x5C[\x30-\x3F]\x20.\x5C[\x10-\x1F]/s', $after)) {
                    return $this->far(unpack('v', $m[1][0])[1], $page);
                }
            }
        }

        return null;
    }

    /** MIL byte of a class record, or null when unknown. */
    private function milOf(Bin $bin, array $class, int $value): ?int
    {
        $at = $class['records'] === null ? null : $class['records'] + $value * self::CLASS_RECORD_SIZE;

        return $at !== null && $at < $bin->size ? $bin->u8($at) : null;
    }

    private function milLabel(Bin $bin, array $class, int $value): string
    {
        if ($value === 0) {
            return ' (not stored)';
        }
        $mil = $this->milOf($bin, $class, $value);

        return match (true) {
            $mil === null => '',
            $mil === 0 => ' (stored, no MIL)',
            default => ' (stored, MIL)',
        };
    }

    private function milSummary(Bin $bin, array $class, int $count): string
    {
        $used = array_unique(array_filter(array_values(unpack('C*', $bin->slice($class['table'], $count)))));
        sort($used);
        $parts = array_map(fn (int $c): string => $c.($this->milOf($bin, $class, $c) ? '=MIL' : '=no MIL'), $used);

        return implode(', ', $parts);
    }

    /** DPP-relative near pointer -> file offset. */
    private function near(int $pointer): ?int
    {
        return $this->far($pointer & 0x3FFF, $this->dpp[$pointer >> 14]);
    }

    /** Page + 14-bit offset -> file offset. */
    private function far(int $offset, int $page): ?int
    {
        $off = ($page & 0x3FF) * 0x4000 + ($offset & 0x3FFF) - self::FLASH_BASE;

        return $off >= 0 ? $off : null;
    }
}
