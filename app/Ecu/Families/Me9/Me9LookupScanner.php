<?php

namespace App\Ecu\Families\Me9;

use App\Ecu\Bin;

/**
 * Harvests ME9 map lookups from MPC5xx (PowerPC, big-endian) code.
 *
 * The lookup library is recognised by fingerprint (Me9Routines), then every "bl" into it is
 * decoded: the argument registers are traced backwards to constants (li, lis/addi, addi rX,r2,d)
 * or RAM loads (lbz/lhz rX,d(r13) ...). Calibration is addressed through the small-data base r2
 * (or lis/addi pairs) in a mirror of the flash (ME9.0 Ford/Volvo: 0x400000 + file offset), RAM
 * through r13. Both base registers are taken from their initialisation in the start-up code.
 *
 * Group maps work in two steps like on ME7: an axis-search routine stores index/fraction of a
 * shared axis to a RAM variable, the interpolation kernel later reads two such variables.
 */
class Me9LookupScanner
{
    public int $r2 = 0;

    public int $r13 = 0;

    /** Flash mirror base of calibration addresses (CPU address - file offset). */
    public int $calBase = 0;

    /** Calibration window in the file [calLo, calHi). */
    public int $calLo = 0;

    public int $calHi = 0;

    /** @var array<int, array> routine address => descriptor (kind, types) */
    public array $routines = [];

    /** @var array<string, array> index RAM var => axis (vals, n, type, in, inw) */
    public array $axisVars = [];

    /**
     * Harvested tables keyed by data offset:
     * ['kind', 'data', 'rows', 'cols', 'type', 'step', 'fn', 'refs', 'row' => axis, 'col' => ?axis]
     * axis: ['vals' => offset of first value, 'n', 'type', 'in' => RAM var hex or null, 'inw' => 1|2|null].
     * Curves have 'cols' = 1 and 'col' = null.
     *
     * @var array<int, array>
     */
    public array $tables = [];

    /** @var int[] 1-based big-endian words */
    private array $w = [];

    /** @var array<int, int[]> bl target => call sites */
    private array $callers = [];

    /** @param  array<string, array>  $fingerprints  body hash => descriptor (see Me9Routines) */
    public function __construct(public Bin $bin, private array $fingerprints = Me9Routines::TABLE) {}

    /** Scan the file; false when it is not understood (no lookup library or base registers). */
    public function scan(): bool
    {
        $size = $this->bin->size;
        if ($size < 0x10000 || $size % 4 !== 0) {
            return false;
        }
        $this->w = unpack('N*', $this->bin->data);
        $this->collectCalls();
        $this->identifyRoutines();
        if (! $this->routines || ! $this->findBases()) {
            return false;
        }

        $kernelCalls = [];
        foreach ($this->routines as $fn => $desc) {
            foreach ($this->callers[$fn] ?? [] as $at) {
                if ($at >= $this->calLo && $at < $this->calHi) {
                    continue;
                }
                foreach ($this->argumentSets($at) as $args) {
                    if ($desc['kind'] === 'KER') {
                        $kernelCalls[] = [$at, $fn, $desc, $args];
                    } else {
                        $this->handle($at, $fn, $desc, $args);
                    }
                }
            }
        }
        foreach ($kernelCalls as [$at, $fn, $desc, $args]) {
            $this->groupMap($at, $fn, $desc, $args);
        }
        ksort($this->tables);

        return true;
    }

    // ---------------------------------------------------------------- code access

    public function word(int $o): int
    {
        return $this->w[($o >> 2) + 1] ?? 0;
    }

    private static function s16(int $v): int
    {
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function collectCalls(): void
    {
        $size = $this->bin->size;
        foreach ($this->w as $i => $w) {
            if (($w >> 26) !== 18 || ($w & 3) !== 1) {
                continue;
            }
            $li = $w & 0x3FFFFFC;
            if ($li & 0x2000000) {
                $li -= 0x4000000;
            }
            $at = ($i - 1) << 2;
            $target = $at + $li;
            if ($target >= 0 && $target < $size) {
                $this->callers[$target][] = $at;
            }
        }
    }

    /** md5 prefix of a routine body: words up to the first blr, calls masked. */
    public static function bodyHash(Bin $bin, int $at): ?string
    {
        $bytes = '';
        for ($o = $at, $n = 0; $n < 400 && $o + 4 <= $bin->size; $o += 4, $n++) {
            $w = $bin->u32($o, true);
            if (($w >> 26) === 18 && ($w & 1)) {
                $w = 0x48000001;
            }
            $bytes .= pack('N', $w);
            if ($w === 0x4E800020) {
                return substr(md5($bytes), 0, 16);
            }
        }

        return null;
    }

    private function identifyRoutines(): void
    {
        foreach (array_keys($this->callers) as $target) {
            if ($target % 4) {
                continue;
            }
            $hash = self::bodyHash($this->bin, $target);
            if ($hash !== null && isset($this->fingerprints[$hash])) {
                $this->routines[$target] = $this->fingerprints[$hash];
            }
        }
        // Wrappers take the value type from the interpolation kernel they call.
        foreach ($this->routines as $at => $desc) {
            if (! array_key_exists('z', $desc) || $desc['z'] !== null) {
                continue;
            }
            $kernel = null;
            for ($o = $at; $o < $at + 1600 && $this->word($o) !== 0x4E800020; $o += 4) {
                $w = $this->word($o);
                if (($w >> 26) === 18 && ($w & 3) === 1) {
                    $li = $w & 0x3FFFFFC;
                    $t = $o + ($li & 0x2000000 ? $li - 0x4000000 : $li);
                    $kernel = $this->routines[$t] ?? null;
                    break;
                }
            }
            if ($kernel && $kernel['kind'] === 'KER') {
                $this->routines[$at]['z'] = $kernel['z'];
                $this->routines[$at]['step'] = $kernel['step'] ?? false;
            } else {
                unset($this->routines[$at]);
            }
        }
    }

    /**
     * r2 / r13 from "lis rN,hi ; addi|ori rN,rN,lo". With several initialisations (boot block and
     * application) the one closest to the lookup library is used.
     */
    private function findBases(): bool
    {
        $library = array_keys($this->routines);
        sort($library);
        $near = $library[intdiv(count($library), 2)];
        $r2 = $this->baseInit(2, $near);
        if ($r2 === null) {
            return false;
        }
        [$this->r2, $r2At] = $r2;
        $r13 = $this->baseInit(13, $r2At);
        if ($r13 === null) {
            return false;
        }
        $this->r13 = $r13[0];

        $size = $this->bin->size;
        $span = 1;
        while ($span < $size) {
            $span <<= 1;
        }
        $this->calBase = $this->r2 >= $size ? $this->r2 & ~($span - 1) : 0;
        $anchor = $this->r2 - $this->calBase;
        if ($anchor < 0 || $anchor >= $size) {
            return false;
        }
        $block = 0x1000;
        $lo = $anchor & ~($block - 1);
        while ($lo >= $block && ! $this->erased($lo - $block, $block)) {
            $lo -= $block;
        }
        $hi = ($anchor & ~($block - 1)) + $block;
        while ($hi + $block <= $size && ! $this->erased($hi, $block)) {
            $hi += $block;
        }
        $this->calLo = $lo;
        $this->calHi = min($hi, $size);

        return true;
    }

    private function erased(int $o, int $len): bool
    {
        return strspn($this->bin->data, "\xFF", $o, $len) === $len;
    }

    /** @return array{0: int, 1: int}|null value and location of the initialisation closest to $near */
    private function baseInit(int $reg, int $near): ?array
    {
        $lis = 0x3C00 | ($reg << 5);
        $addi = 0x3800 | ($reg << 5) | $reg;
        $ori = 0x6000 | ($reg << 5) | $reg;
        $best = null;
        foreach ($this->w as $i => $w) {
            if (($w >> 16) !== $lis) {
                continue;
            }
            $next = $this->w[$i + 1] ?? 0;
            $hi = ($w & 0xFFFF) << 16;
            $value = match ($next >> 16) {
                $addi => ($hi + self::s16($next & 0xFFFF)) & 0xFFFFFFFF,
                $ori => $hi | ($next & 0xFFFF),
                default => null,
            };
            $at = ($i - 1) << 2;
            if ($value !== null && ($best === null || abs($at - $near) < abs($best[1] - $near))) {
                $best = [$value, $at];
            }
        }

        return $best;
    }

    /** Calibration address -> file offset inside the calibration window, or null. */
    public function cal(int $addr): ?int
    {
        foreach ([$addr - $this->calBase, $addr] as $o) {
            if ($o >= $this->calLo && $o < $this->calHi) {
                return $o;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- register tracing

    /**
     * Argument registers r3..r9 at a call site. Besides the straight-line path, a value may be
     * set on a branch that jumps into the argument setup ("addi r3,... ; b L" / "L: bl ..."):
     * each such path yields an extra argument set.
     *
     * @return array<int, array<int, ?array>>
     */
    private function argumentSets(int $at): array
    {
        $sets = [$this->args($at, null)];
        for ($k = 1; $k <= 16 && $at - 4 * $k >= 0; $k++) {
            $o = $at - 4 * $k;
            $w = $this->word($o);
            if ($w === 0x4E800020 || ($w >> 16) === 0x9421) {
                break;
            }
            if (($w >> 26) === 18 && ($w & 3) === 0) {
                $li = $w & 0x3FFFFFC;
                $target = $o + ($li & 0x2000000 ? $li - 0x4000000 : $li);
                if ($target > $o && $target <= $at) {
                    $alt = $this->args($at, [$target, $o]);
                    if ($alt != $sets[0]) {
                        $sets[] = $alt;
                    }
                }
            }
        }

        return $sets;
    }

    /** @param  array{0: int, 1: int}|null  $jump  [join address, branch address] */
    private function args(int $at, ?array $jump): array
    {
        $r = [];
        for ($reg = 3; $reg <= 9; $reg++) {
            $r[$reg] = $this->resolve($reg, $at, 0, $jump);
        }

        return $r;
    }

    /**
     * Value of a register just before $pc: ['c', value] constant, ['m', address, type] memory
     * load, ['?'] computed, null when the trace hits a call, branch or function start.
     *
     * @param  array{0: int, 1: int}|null  $jump
     */
    public function resolve(int $reg, int $pc, int $depth = 0, ?array $jump = null): ?array
    {
        if ($reg === 2) {
            return ['c', $this->r2];
        }
        if ($reg === 13) {
            return ['c', $this->r13];
        }
        if ($reg === 0 || $depth > 6) {
            return null;
        }
        $limit = $reg >= 14 ? 400 : 80;
        for ($o = $pc - 4, $n = 0; $n < $limit && $o >= 0; $o -= 4, $n++) {
            if ($jump && $o === $jump[0] - 4) {
                $o = $jump[1] - 4;
                $jump = null;
            }
            $w = $this->word($o);
            $op = $w >> 26;
            if ($w === 0x4E800020) {
                return null;
            }
            if ($op === 18 && (($w & 1) ? $reg <= 12 : $reg < 14)) {
                return null;
            }
            if (($w >> 16) === 0x9421 && $reg >= 14) {
                return null;
            }
            $d = ($w >> 21) & 31;
            $a = ($w >> 16) & 31;
            $imm = $w & 0xFFFF;

            if (($op === 14 || $op === 15) && $d === $reg) {   // addi / addis (li / lis)
                $v = $op === 14 ? self::s16($imm) : $imm << 16;
                if ($a === 0) {
                    return ['c', $v & 0xFFFFFFFF];
                }
                $base = $this->resolve($a, $o, $depth + 1);

                return $base && $base[0] === 'c' ? ['c', ($base[1] + $v) & 0xFFFFFFFF] : null;
            }
            if ($op === 24 && $a === $reg) {                     // ori
                $base = $this->resolve($d, $o, $depth + 1);

                return $base && $base[0] === 'c' ? ['c', $base[1] | $imm] : null;
            }
            if (in_array($op, [32, 33, 34, 35, 40, 41, 42, 43], true) && $d === $reg) {   // lwz lbz lhz lha
                $type = match ($op) {
                    34, 35 => 'u8',
                    40, 41 => 'u16',
                    42, 43 => 's16',
                    default => 'u32',
                };
                $base = $a === 0 ? ['c', 0] : $this->resolve($a, $o, $depth + 1);

                return $base && $base[0] === 'c' ? ['m', ($base[1] + self::s16($imm)) & 0xFFFFFFFF, $type] : ['?'];
            }
            if ($op === 31) {
                $xo = ($w >> 1) & 0x3FF;
                $b = ($w >> 11) & 31;
                if ($xo === 444 && $a === $reg) {                // or (mr)
                    return $d === $b ? $this->resolve($d, $o, $depth + 1) : ['?'];
                }
                if (($xo === 954 || $xo === 922) && $a === $reg) {   // extsb / extsh
                    return $this->resolve($d, $o, $depth + 1);
                }
                $toA = [28, 60, 124, 284, 316, 412, 476, 24, 536, 792, 824, 26];
                if (in_array($xo, $toA, true) && $a === $reg) {
                    return ['?'];
                }
                $noD = [0, 32, 151, 407, 215, 247, 150, 183, 439, 467, 144];
                if (! in_array($xo, $toA, true) && ! in_array($xo, $noD, true) && ! in_array($xo, [444, 954, 922], true) && $d === $reg) {
                    return ['?'];
                }
            } elseif ($op === 21 && $a === $reg) {               // rlwinm: only plain masking keeps the value
                return (($w >> 11) & 31) === 0 ? $this->resolve($d, $o, $depth + 1) : ['?'];
            } elseif (in_array($op, [20, 23, 25, 26, 27, 28, 29], true) && $a === $reg) {
                return ['?'];
            } elseif (in_array($op, [7, 8, 12, 13], true) && $d === $reg) {
                return ['?'];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- table harvesting

    private function read(int $o, string $type): int
    {
        return $this->bin->read($o, $type, true);
    }

    private static function size(string $type): int
    {
        return Bin::typeSize($type);
    }

    private function constant(?array $v): ?int
    {
        return $v && $v[0] === 'c' ? $this->cal($v[1]) : null;
    }

    /** RAM input variable of an argument. */
    private static function input(?array $v): array
    {
        if ($v && $v[0] === 'm') {
            return [sprintf('%X', $v[1]), $v[2] === 'u8' ? 1 : ($v[2] === 'u32' ? 4 : 2)];
        }

        return [null, null];
    }

    /** Point count passed as immediate or loaded from the calibration. */
    private function count(?array $v): ?int
    {
        if ($v && $v[0] === 'c' && $v[1] < 256) {
            return $v[1];
        }
        if ($v && $v[0] === 'm' && ($o = $this->cal($v[1])) !== null) {
            return $this->read($o, $v[2] === 'u8' ? 'u8' : 'u16');
        }

        return null;
    }

    private function axis(int $vals, ?int $n, string $type, ?array $input): ?array
    {
        if ($n === null || $n < 2 || $n > 64 || ! $this->inCal($vals, $n * self::size($type))) {
            return null;
        }
        $prev = null;
        for ($i = 0; $i < $n; $i++) {
            $v = $this->read($vals + $i * self::size($type), $type);
            if ($prev !== null && $v < $prev) {
                return null;
            }
            $prev = $v;
        }
        if ($this->read($vals, $type) >= $prev) {
            return null;
        }
        [$in, $inw] = self::input($input);

        return ['vals' => $vals, 'n' => $n, 'type' => $type, 'in' => $in, 'inw' => $inw];
    }

    private function inCal(int $o, int $len): bool
    {
        return $o >= $this->calLo && $o + $len <= $this->calHi;
    }

    private function add(string $kind, int $data, array $row, ?array $col, string $type, bool $step, int $fn, int $at): void
    {
        $rows = $row['n'];
        $cols = $col ? $col['n'] : 1;
        if (! $this->inCal($data, $rows * $cols * self::size($type))) {
            return;
        }
        if (isset($this->tables[$data])) {
            if (! in_array($at, $this->tables[$data]['refs'], true)) {
                $this->tables[$data]['refs'][] = $at;
            }

            return;
        }
        $this->tables[$data] = [
            'kind' => $kind, 'data' => $data, 'rows' => $rows, 'cols' => $cols, 'type' => $type, 'step' => $step,
            'fn' => $fn, 'refs' => [$at], 'row' => $row, 'col' => $col,
        ];
    }

    private function handle(int $at, int $fn, array $desc, array $a): void
    {
        $step = $desc['step'] ?? false;
        switch ($desc['kind']) {
            case 'KL':
                if (($h = $this->constant($a[3])) === null) {
                    return;
                }
                $cs = self::size($desc['count']);
                $n = $this->read($h, $desc['count']);
                $row = $this->axis($h + $cs, $n, $desc['x'], $a[4]);
                if ($row) {
                    $this->add('KL', $h + $cs + $n * self::size($desc['x']), $row, null, $desc['z'], $step, $fn, $at);
                }
                break;
            case 'KF':
                if (($h = $this->constant($a[3])) === null) {
                    return;
                }
                $cs = self::size($desc['count']);
                $nx = $this->read($h, $desc['count']);
                $ny = $this->read($h + $cs, $desc['count']);
                $row = $this->axis($h + 2 * $cs, $nx, $desc['x'], $a[4]);
                $col = $row ? $this->axis($h + 2 * $cs + $nx * self::size($desc['x']), $ny, $desc['y'], $a[5]) : null;
                if ($row && $col) {
                    $this->add('KF', $col['vals'] + $ny * self::size($desc['y']), $row, $col, $desc['z'], $step, $fn, $at);
                }
                break;
            case 'KFS':
                $x = $this->constant($a[4]);
                $y = $this->constant($a[6]);
                $z = $this->constant($a[7]);
                if ($x === null || $y === null || $z === null) {
                    return;
                }
                $row = $this->axis($x, $this->count($a[3]), $desc['x'], $a[8]);
                $col = $this->axis($y, $this->count($a[5]), $desc['y'], $a[9]);
                if ($row && $col) {
                    $this->add('KFS', $z, $row, $col, $desc['z'], $step, $fn, $at);
                }
                break;
            case 'KLS':
                $x = $this->constant($a[4]);
                $z = $this->constant($a[5]);
                if ($x === null || $z === null) {
                    return;
                }
                $row = $this->axis($x, $this->count($a[3]), $desc['x'], $a[6]);
                if ($row) {
                    $this->add('KLS', $z, $row, null, $desc['z'], $step, $fn, $at);
                }
                break;
            case 'AX':
                $this->axisSearch($at, $desc, $a);
                break;
        }
    }

    /** Axis search: remember which RAM variable receives index/fraction of which axis. */
    private function axisSearch(int $at, array $desc, array $a): void
    {
        if (($h = $this->constant($a[3])) === null) {
            return;
        }
        $type = $desc['x'];
        $countType = self::size($type) === 1 ? 'u8' : 'u16';
        $axis = $this->axis($h + self::size($type), $this->read($h, $countType), $type, $a[4]);
        if (! $axis) {
            return;
        }
        for ($k = 1; $k <= 4; $k++) {
            $w = $this->word($at + 4 * $k);
            if (($w >> 26) === 36 && (($w >> 21) & 31) === 3 && (($w >> 16) & 31) === 13) {
                $var = sprintf('%X', ($this->r13 + self::s16($w & 0xFFFF)) & 0xFFFFFFFF);
                $this->axisVars[$var] ??= $axis;

                return;
            }
        }
    }

    private function groupMap(int $at, int $fn, array $desc, array $a): void
    {
        $z = $this->constant($a[3]);
        $rowVar = $a[5] && $a[5][0] === 'm' ? sprintf('%X', $a[5][1]) : null;
        $colVar = $a[6] && $a[6][0] === 'm' ? sprintf('%X', $a[6][1]) : null;
        if ($z === null || ! isset($this->axisVars[$rowVar], $this->axisVars[$colVar])) {
            return;
        }
        $this->add('KFG', $z, $this->axisVars[$rowVar], $this->axisVars[$colVar], $desc['z'], $desc['step'] ?? false, $fn, $at);
    }
}
