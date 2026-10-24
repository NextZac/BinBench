<?php

namespace App\Ecu\Families\Me7;

use App\Ecu\Bin;

/**
 * Harvests ME7 map lookups from C167 code.
 *
 * ME7 reads calibration through a handful of library routines whose arguments are
 * loaded into r12..r15 (and sometimes pushed on the R0 stack) right before a CALLS:
 *  - axis prep (SSTB):   ptr=#axis, then input var [, old index] -> r4 stored to an index var
 *  - group map (GKF):    ptr=#data, ptr=#column axis, row index var, column index var
 *  - group curve (GKL):  ptr=#data, index var
 *  - map with own axes:  ptr=#header (nx, ny, axis x, axis y, data), input x, input y
 *  - curve with own axis: ptr=#header (n, axis, data), input var
 * A pointer is either a DPP-relative near pointer in one register, or a far pair
 * (offset, page) in two registers, which shifts the remaining arguments by one.
 */
class LookupScanner
{
    /** @var int[] */
    public array $dpp;

    /** index var => ['axis' => file offset of count, 'n', 'w' => 1|2, 'in' => input var, 'fn'] */
    public array $ivars = [];

    /** key => table array, see addTable() */
    public array $tables = [];

    /** @var array<int, array{at:int, fn:string, args:array, stack:array, store:?string}> */
    public array $calls = [];

    public function __construct(public Bin $bin)
    {
        $this->dpp = C167::dpps($bin);
    }

    /** CPU near pointer (optionally with explicit page) -> file offset, or null when outside the dump. */
    public function near(int $ptr, ?int $page = null): ?int
    {
        $page ??= $this->dpp[$ptr >> 14];
        $off = $page * 0x4000 + ($ptr & 0x3FFF) - 0x800000;

        return $off >= 0 && $off < $this->bin->size ? $off : null;
    }

    /** RAM variable identity (address only). */
    public static function varKey(?array $r): ?string
    {
        return $r && $r[0] !== 'imm' ? sprintf('%X', $r[1]) : null;
    }

    public function scan(): self
    {
        $bin = $this->bin;
        if (preg_match_all('/\xDA[\x00-\x0F\x80-\x8F]/s', $bin->data, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $at]) {
                if ($at % 2 || $at < 8 || $at + 12 > $bin->size) {
                    continue;
                }
                $regs = C167::regsBefore($bin, $at);
                if (isset($regs[12]) && $regs[12][0] !== 'imm') {
                    $this->stackForm($at, $regs);

                    continue;
                }
                $hooked = false;
                if (! isset($regs[12]) && ($regs['pre'][12][0] ?? '') === 'imm' && ($regs[14][0] ?? '') === 'mem' && ($regs[15][0] ?? '') === 'mem') {
                    // group-map call behind a hook: take the pointer set up before the hook call
                    $regs += $regs['pre'];
                    $hooked = true;
                }
                if (! isset($regs[12])) {
                    continue;
                }
                // Decode the leading pointer (near, or far offset+page) and the remaining args.
                $args = [$regs[13] ?? null, $regs[14] ?? null, $regs[15] ?? null];
                $ptr = $this->pointer($regs[12], $args);
                if ($ptr === null) {
                    continue;
                }
                $this->calls[] = [
                    'at' => $at,
                    'fn' => sprintf('%02X%04X', $bin->u8($at + 1), $bin->u16($at + 2)),
                    'ptr' => $ptr,
                    'args' => $args,
                    'stack' => $regs['stack'] ?? [],
                    'store' => $this->storeAfter($at + 4),
                    'hooked' => $hooked,
                ];
            }
        }
        $this->findAxes();
        $this->findTables();
        $this->addStackTables();
        $this->resolveTypes();

        return $this;
    }

    /**
     * Element width / signedness is a property of the lookup routine, not stored in the bin.
     * Decide per routine from all of its tables: odd addresses force bytes; otherwise compare
     * byte-neighbour vs. word-neighbour roughness. Signed if values wrap around zero.
     */
    private function resolveTypes(): void
    {
        $byFn = [];
        foreach ($this->tables as $k => $t) {
            $byFn[$t['kind'].$t['fn']][] = $k;
        }
        foreach ($byFn as $keys) {
            $w = $this->tables[$keys[0]]['w'];
            if ($w === null) {
                // one vote per table (constant tables abstain): word data has rough neighbouring bytes
                $odd = false;
                $votes = 0;
                foreach ($keys as $k) {
                    $t = $this->tables[$k];
                    $odd = $odd || $t['data'] % 2;
                    $bytes = array_values(unpack('C*', $this->bin->slice($t['data'], min(256, $t['rows'] * $t['cols'] * 2))));
                    $d1 = $d2 = 0;
                    for ($i = 0; $i + 2 < count($bytes); $i++) {
                        $d1 += abs($bytes[$i + 1] - $bytes[$i]);
                        $d2 += abs($bytes[$i + 2] - $bytes[$i]);
                    }
                    if ($d1 + $d2 > 0) {
                        $votes += $d1 > 1.3 * $d2 ? 1 : -1;
                    }
                }
                $w = $odd || $votes <= 0 ? 1 : 2;
            }
            // signedness: neighbours jumping across the 0 / -1 boundary
            $wrap = 0;
            $big = 0;
            $half = $w === 1 ? 0x80 : 0x8000;
            foreach ($keys as $k) {
                $t = $this->tables[$k];
                $n = $t['rows'] * $t['cols'];
                $prev = null;
                for ($i = 0; $i < $n; $i++) {
                    $v = $w === 1 ? $this->bin->u8($t['data'] + $i) : $this->bin->u16($t['data'] + 2 * $i);
                    if ($prev !== null && abs($v - $prev) > $half) {
                        $s1 = $v >= $half ? $v - 2 * $half : $v;
                        $s0 = $prev >= $half ? $prev - 2 * $half : $prev;
                        abs($s1 - $s0) < $half / 4 ? $wrap++ : $big++;
                    }
                    $prev = $v;
                }
            }
            foreach ($keys as $k) {
                $this->tables[$k]['w'] = $w;
                $this->tables[$k]['signed'] = $wrap > 0 && $wrap >= $big;
                $this->tables[$k]['wrap'] = [$wrap, $big];
            }
        }
    }

    /** Map lookups using the stack convention, collected by stackForm(). */
    public array $stackCalls = [];

    /**
     * Map with separate pointers (ME7.5 e.g. LAMFA):
     *   push x input, push y input, push #data (far), push #y axis (far),
     *   r12 = [nx] (byte), r13:r14 = #x axis (far), r15 = [ny] (byte), CALLS.
     */
    private function stackForm(int $at, array $regs): void
    {
        $st = $regs['stack'] ?? [];
        $r12 = $regs[12];
        $r13 = $regs[13] ?? null;
        $r14 = $regs[14] ?? null;
        $r15 = $regs[15] ?? null;
        if (count($st) < 6 || $r12[0] !== 'memb' || ! $r15 || $r15[0] !== 'memb' || ! $r13 || $r13[0] !== 'imm' || ! $r14 || $r14[0] !== 'imm') {
            return;
        }
        [$inY, $inX, $dPg, $dLo, $yPg, $yLo] = array_slice($st, -6); // y input is pushed first
        foreach ([$dPg, $dLo, $yPg, $yLo] as $v) {
            if (! $v || $v[0] !== 'imm') {
                return;
            }
        }
        $nxAt = $this->near($r12[1], $r12[2]);
        $nyAt = $this->near($r15[1], $r15[2]);
        $xAx = $this->near($r13[1], $r14[1]);
        $yAx = $this->near($yLo[1], $yPg[1]);
        $data = $this->near($dLo[1], $dPg[1]);
        if ($nxAt === null || $nyAt === null || $xAx === null || $yAx === null || $data === null) {
            return;
        }
        $nx = $this->bin->u8($nxAt);
        $ny = $this->bin->u8($nyAt);
        if ($nx < 2 || $ny < 2 || $nx > 32 || $ny > 32) {
            return;
        }
        // Axes may be byte or word wide; the y axis is laid out right after the x axis.
        $wx = $this->axisRun($xAx, $nx, 1) && ($yAx - $xAx) <= $nx + 1 ? 1 : ($this->axisRun($xAx, $nx, 2) ? 2 : 0);
        $wy = $this->axisRun($yAx, $ny, 1) && ($data - $yAx) <= $ny + 1 ? 1 : ($this->axisRun($yAx, $ny, 2) ? 2 : 0);
        if (! $wx || ! $wy) {
            return;
        }
        $this->stackCalls[] = [
            'call' => ['at' => $at, 'fn' => sprintf('%02X%04X', $this->bin->u8($at + 1), $this->bin->u16($at + 2)), 'store' => $this->storeAfter($at + 4)],
            'data' => $data,
            'row' => ['axis' => $nxAt, 'vals' => $xAx, 'n' => $nx, 'w' => $wx, 'in' => self::varKey($inX)],
            'col' => ['axis' => $nyAt, 'vals' => $yAx, 'n' => $ny, 'w' => $wy, 'in' => self::varKey($inY)],
        ];
    }

    /** Resolve ptr register (+ following page/segment register, consumed from $args). */
    private function pointer(array $reg, array &$args): ?int
    {
        $next = $args[0];
        if ($next && $next[0] === 'imm' && $next[1] >= 0x80 && $next[1] <= 0x8F) {
            array_shift($args);
            $args[] = null;
            $o = ($next[1] << 16 | $reg[1]) - 0x800000;

            return $o >= 0 && $o < $this->bin->size ? $o : null;
        }
        if ($next && $next[0] === 'imm' && $next[1] >= 0x200 && $next[1] < 0x240) {
            array_shift($args);
            $args[] = null;

            return $this->near($reg[1], $next[1]);
        }

        return $this->near($reg[1]);
    }

    /** "mov mem,r4" / "movb mem,rl4" right after the call (optionally behind an EXTP). */
    private function storeAfter(int $o): ?string
    {
        $b = $this->bin;
        if ($b->u8($o) === 0xD7) {
            $o += 4;
        }
        $op = $b->u8($o);
        $r = $b->u8($o + 1);

        return ($op === 0xF6 && $r === 0xF4) || ($op === 0xF7 && $r === 0xF8) ? sprintf('%X', $b->u16($o + 2)) : null;
    }

    /** Validate an axis at $o: count (u8/u16) then non-decreasing values. Returns n or 0. */
    public function axisAt(int $o, int $w): int
    {
        if ($o + 2 > $this->bin->size) {
            return 0;
        }
        $n = $w === 2 ? $this->bin->u16($o) : $this->bin->u8($o);
        if ($n < 2 || $n > 32 || $o + $w + $n * $w > $this->bin->size) {
            return 0;
        }

        return $this->axisRun($o + $w, $n, $w) ? $n : 0;
    }

    /** n values non-decreasing with at most one repeat. */
    public function axisRun(int $o, int $n, int $w): bool
    {
        $prev = -1;
        $flat = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = $w === 2 ? $this->bin->u16($o + $i * 2) : $this->bin->u8($o + $i);
            if ($v < $prev) {
                return false;
            }
            $flat += $v === $prev ? 1 : 0;
            $prev = $v;
        }

        return $flat <= 1 && $prev > 0;
    }

    private function findAxes(): void
    {
        $cand = [];
        $oldForm = [];
        foreach ($this->calls as $c) {
            [$in, $old] = $c['args'];
            if ($c['store'] === null || ! $in || $in[0] === 'imm') {
                continue;
            }
            if ($old && ($old[0] === 'imm' || sprintf('%X', $old[1]) !== $c['store'])) {
                continue;
            }
            foreach ([2, 1] as $w) {
                if ($n = $this->axisAt($c['ptr'], $w)) {
                    $cand[$c['store']] = ['axis' => $c['ptr'], 'n' => $n, 'w' => $w, 'in' => self::varKey($in), 'fn' => $c['fn']];
                    $oldForm[$c['fn']] = ($oldForm[$c['fn']] ?? 0) + ($old ? 1 : 0);
                    break;
                }
            }
        }
        // An SSTB routine is one whose results are used as indexes by other lookups (group maps,
        // group curves). Group-map use, or the "old index passed in" calling form, separates it
        // from curve lookups with the same register shape.
        $gkf = [];
        $all = [];
        foreach ($this->calls as $c) {
            foreach ($this->gkfIndexVars($c) as $k) {
                if (isset($cand[$k])) {
                    $gkf[$cand[$k]['fn']][$k] = true;
                    $all[$cand[$k]['fn']][$k] = true;
                }
            }
            $k = self::varKey($c['args'][0]);
            if ($k && ! $c['args'][1] && isset($cand[$k])) {
                $all[$cand[$k]['fn']][$k] = true;
            }
        }
        $sstb = [];
        foreach ($all as $fn => $vars) {
            if (count($vars) >= 2 && (isset($gkf[$fn]) || ($oldForm[$fn] ?? 0) >= 2)) {
                $sstb[] = (string) $fn; // numeric-looking keys come back as int
            }
        }
        $this->ivars = array_filter($cand, fn ($a) => in_array($a['fn'], $sstb, true));
    }

    /** Possible index vars of a group-map call: two mem args after an immediate, or two pushed vars. */
    private function gkfIndexVars(array $c): array
    {
        [$a, $b, $d] = $c['args'];
        $out = [];
        if ($a && $a[0] === 'imm') {
            foreach ([$b, $d] as $x) {
                if ($x && $x[0] === 'mem') {
                    $out[] = self::varKey($x);
                }
            }
            foreach (array_slice($c['stack'], -2) as $x) {
                if ($x && $x[0] === 'mem') {
                    $out[] = self::varKey($x);
                }
            }
        }

        return $out;
    }

    private function findTables(): void
    {
        $sstb = array_unique(array_column($this->ivars, 'fn'));
        foreach ($this->calls as $c) {
            if (in_array($c['fn'], $sstb, true)) {
                continue;
            }
            $o = $c['ptr'];
            [$a1, $a2, $a3] = $c['args'];
            $k1 = self::varKey($a1);
            $k2 = self::varKey($a2);
            $k3 = self::varKey($a3);

            // Group map: #column axis then two index vars (in registers or pushed on the stack).
            if (($a1 && $a1[0] === 'imm') || ($c['hooked'] && ! $a1)) {
                $idx = $k2 && $k3 ? [$k2, $k3] : [];
                if (! $idx || ! isset($this->ivars[$idx[0]], $this->ivars[$idx[1]])) {
                    $st = array_map([self::class, 'varKey'], array_slice($c['stack'], -2));
                    $idx = count($st) === 2 && isset($this->ivars[$st[0]], $this->ivars[$st[1]]) ? $st : [];
                    // pushed order is not fixed: the column index is the one whose axis is the #column pointer
                    if ($idx && $a1) {
                        $colAxis = $this->pointerArg($a1, $a2);
                        if ($colAxis !== null && $this->ivars[$idx[0]]['axis'] === $colAxis) {
                            $idx = [$idx[1], $idx[0]];
                        }
                    }
                }
                if ($idx) {
                    $this->addTable($c, 'GKF', $o, $this->ivars[$idx[0]], $this->ivars[$idx[1]]);
                }

                continue;
            }
            // Group curve on a shared axis.
            if ($k1 && isset($this->ivars[$k1]) && ! $a2) {
                $this->addTable($c, 'GKL', $o, $this->ivars[$k1], null);

                continue;
            }
            // Map with own axes (word or byte header).
            if ($k1 && $k2) {
                foreach ([2, 1] as $w) {
                    $nx = $w === 2 ? $this->bin->u16($o) : $this->bin->u8($o);
                    $ny = $w === 2 ? $this->bin->u16($o + $w) : $this->bin->u8($o + 1);
                    if ($nx < 2 || $ny < 2 || $nx > 32 || $ny > 32) {
                        continue;
                    }
                    $xv = $o + 2 * $w;
                    $yv = $xv + $nx * $w;
                    if ($this->axisRun($xv, $nx, $w) && $this->axisRun($yv, $ny, $w)) {
                        $this->addTable($c, 'KF', $yv + $ny * $w,
                            ['axis' => $o, 'vals' => $xv, 'n' => $nx, 'w' => $w, 'in' => $k1],
                            ['axis' => $o + $w, 'vals' => $yv, 'n' => $ny, 'w' => $w, 'in' => $k2], $w, $o);
                        break;
                    }
                }

                continue;
            }
            // Curve with own axis.
            if ($k1 && ! $a2) {
                foreach ([2, 1] as $w) {
                    if ($n = $this->axisAt($o, $w)) {
                        $this->addTable($c, 'KL', $o + $w + $n * $w,
                            ['axis' => $o, 'vals' => $o + $w, 'n' => $n, 'w' => $w, 'in' => $k1], null, $w, $o);
                        break;
                    }
                }
            }
        }
    }

    private function addStackTables(): void
    {
        foreach ($this->stackCalls as $s) {
            $this->addTable($s['call'], 'KFS', $s['data'], $s['row'], $s['col'], null, $s['row']['axis']);
        }
    }

    /** Pointer passed as an argument (near, or far when followed by a page). */
    private function pointerArg(array $a, ?array $next): ?int
    {
        if ($next && $next[0] === 'imm' && $next[1] >= 0x200 && $next[1] < 0x240) {
            return $this->near($a[1], $next[1]);
        }

        return $this->near($a[1]);
    }

    private function addTable(array $c, string $kind, int $data, array $row, ?array $col, ?int $w = null, ?int $header = null): void
    {
        foreach ([&$row, &$col] as &$a) {
            if ($a && ! isset($a['vals'])) {
                $a['vals'] = $a['axis'] + $a['w'];
            }
        }
        unset($a);
        $key = $kind.':'.$data;
        if (isset($this->tables[$key])) {
            $this->tables[$key]['sites'][] = $c['at'];

            return;
        }
        $this->tables[$key] = [
            'kind' => $kind,
            'data' => $data,
            'header' => $header,
            'rows' => $row['n'],
            'cols' => $col['n'] ?? 1,
            'row' => $row,
            'col' => $col,
            'w' => $w,          // element width when known from the header, else per lookup routine
            'fn' => $c['fn'],
            'sites' => [$c['at']],
            'store' => $c['store'],
            'hooked' => $c['hooked'] ?? false,
        ];
    }
}
