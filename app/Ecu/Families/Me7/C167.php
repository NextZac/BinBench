<?php

namespace App\Ecu\Families\Me7;

use App\Ecu\Bin;

/**
 * Minimal Infineon C166/C167 helpers: instruction length, DPP lookup and a
 * backwards "what are r12..r15 just before this CALLS" register tracker.
 * Enough to read the argument set-up of ME7 map lookup calls.
 */
class C167
{
    /** Instruction length is fixed per opcode: low nibble 2..7 and A are 4 bytes. */
    public static function len(int $op): int
    {
        $lo = $op & 0x0F;

        return ($lo >= 2 && $lo <= 7) || $lo === 0x0A ? 4 : 2;
    }

    /** Opcodes after which register state is not trusted (branches, calls, returns). */
    public static function isFlow(int $op): bool
    {
        $lo = $op & 0x0F;

        return $lo === 0x0D                                  // JMPR cc
            || in_array($op, [0xEA, 0xFA, 0xDA, 0xCA, 0xBB, 0xCB, 0xDB, 0xEB, 0xFB, 0x8A, 0x9A, 0xAA, 0xBA, 0x9C, 0xAB, 0xE2], true);
    }

    /**
     * Find the DPP0..DPP3 values set up by the start-up code ("mov DPPn,#page").
     * ME7 maps 0x800000+ (flash) through DPP0/DPP1 = 0x204/0x205, DPP2 = 0xE0 (RAM), DPP3 = 3.
     *
     * @return int[]
     */
    public static function dpps(Bin $bin): array
    {
        $dpp = [0x204, 0x205, 0xE0, 0x03];
        foreach ([0, 1, 2] as $n) {
            $count = [];
            foreach ($bin->findPattern(sprintf('E6 %02X XX XX', $n), 0, 400) as $o) {
                if ($o % 2) {
                    continue;
                }
                $v = $bin->u16($o + 2);
                $ok = $n < 2 ? ($v >= 0x200 && $v < 0x240) : ($v >= 0xE0 && $v < 0x100);
                if ($ok) {
                    $count[$v] = ($count[$v] ?? 0) + 1;
                }
            }
            if ($count) {
                arsort($count);
                $dpp[$n] = array_key_first($count);
            }
        }

        return $dpp;
    }

    /**
     * Track r4..r15 just before the instruction at $p by decoding a short window backwards.
     * Returns reg => ['imm', value] | ['mem', addr, page|null] | ['memb', addr, page|null],
     * plus 'stack' => values pushed with mov [-R0],Rn (in push order).
     */
    public static function regsBefore(Bin $bin, int $p, int $window = 80): array
    {
        $start = null;
        for ($s = max(0, $p - $window); $s < $p; $s += 2) {
            $q = $s;
            while ($q < $p) {
                $q += self::len($bin->u8($q));
            }
            if ($q === $p) {
                $start = $s;
                break;
            }
        }
        if ($start === null) {
            return [];
        }
        $regs = [];
        $ext = null; // ['p'|'s', value, remaining]
        for ($q = $start; $q < $p; $q += self::len($op)) {
            $op = $bin->u8($q);
            $b1 = $bin->u8($q + 1);
            if (self::isFlow($op)) {
                // Remember the state before a CALLS: patched files (multimap, launch control) often
                // insert a hook call between the pointer set-up and the original lookup.
                $pre = $op === 0xDA ? $regs : null;
                unset($pre['pre'], $pre['stack']);
                $regs = $pre ? ['pre' => $pre] : [];
                $ext = null;

                continue;
            }
            $page = null;
            if ($ext) {
                $page = $ext[0] === 'p' ? $ext[1] : null;
                if (--$ext[2] <= 0) {
                    $ext = null;
                }
            }
            switch ($op) {
                case 0xD7: // EXTP/EXTS #val,#n
                    $type = ($b1 >> 6) & 3;
                    $ext = [($type & 1) ? 'p' : 's', $bin->u16($q + 2) & 0x3FF, (($b1 >> 4) & 3) + 1];
                    break;
                case 0xE6: // mov reg,#imm16
                    if (($b1 & 0xF0) === 0xF0) {
                        $regs[$b1 & 0x0F] = ['imm', $bin->u16($q + 2)];
                    }
                    break;
                case 0xF2: // mov reg,mem
                case 0xC2: // movbz reg,mem
                    if (($b1 & 0xF0) === 0xF0) {
                        $regs[$b1 & 0x0F] = [$op === 0xF2 ? 'mem' : 'memb', $bin->u16($q + 2), $page];
                    }
                    break;
                case 0xE0: // mov Rn,#data4
                    $regs[$b1 & 0x0F] = ['imm', $b1 >> 4];
                    break;
                case 0xF6: // mov mem,reg: afterwards reg holds that variable (typ. a lookup result in r4)
                    if (($b1 & 0xF0) === 0xF0 && ! isset($regs[$b1 & 0x0F])) {
                        $regs[$b1 & 0x0F] = ['mem', $bin->u16($q + 2), $page];
                    }
                    break;
                case 0x88: // mov [-R0],Rn (push argument)
                    if (($b1 & 0x0F) === 0) {
                        $regs['stack'][] = $regs[$b1 >> 4] ?? null;
                    }
                    break;
                case 0xF0: // mov Rn,Rm
                    $src = $regs[$b1 & 0x0F] ?? null;
                    if ($src) {
                        $regs[$b1 >> 4] = $src;
                    } else {
                        unset($regs[$b1 >> 4]);
                    }
                    break;
                default:
                    // Any other op with a GPR destination in the low nibble of byte 1 (2-byte ALU ops)
                    // or the reg field of 4-byte ops invalidates that register.
                    if (self::len($op) === 2 && ($op & 0x0F) <= 1) {
                        unset($regs[$b1 >> 4]);
                    } elseif (self::len($op) === 4 && ($b1 & 0xF0) === 0xF0 && ! in_array($op & 0x0F, [4, 5], true) && $op !== 0xF6 && $op !== 0xF7) {
                        unset($regs[$b1 & 0x0F]);
                    }
            }
        }

        return $regs;
    }
}
