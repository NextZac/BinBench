<?php

namespace App\Ecu\Families\Me7;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Names the tables harvested by LookupScanner.
 *
 * Nothing here relies on fixed addresses: maps are recognised by how the code uses them
 * (lookup routine, shared axes, input variables) plus value plausibility. The engine-speed
 * and load variables are discovered first (nmot_w / nmot / rl_w / rl differ per software).
 */
class MapCatalog
{
    private ?string $nmotW = null;

    private ?string $nmotB = null;

    private ?string $rlW = null;

    private ?string $rlB = null;

    /** @var array<string, MapDef> table key => named map */
    private array $named = [];

    /** @var MapDef[] maps found without a lookup-table entry (scalars, pattern hits) */
    private array $extra = [];

    public function __construct(private Bin $bin, private LookupScanner $scan) {}

    /** @return MapDef[] */
    public function build(bool $includeUnnamed = true): array
    {
        $this->discoverVars();
        $this->ignition();
        $this->torque();
        $this->boost();
        $this->fuel();
        $this->limiters();

        $out = array_values($this->named);
        foreach ($this->extra as $m) {
            $out[] = $m;
        }
        if ($includeUnnamed) {
            foreach ($this->scan->tables as $key => $t) {
                if (! isset($this->named[$key])) {
                    $out[] = $this->generic($t);
                }
            }
        }
        // De-duplicate by address (pattern hits may coincide with harvested tables).
        $seen = [];
        $res = [];
        foreach ($out as $m) {
            if (! isset($seen[$m->address])) {
                $seen[$m->address] = true;
                $res[] = $m;
            }
        }

        return $res;
    }

    public function vars(): array
    {
        return ['nmot_w' => $this->nmotW, 'nmot' => $this->nmotB, 'rl_w' => $this->rlW, 'rl' => $this->rlB];
    }

    // ---------------------------------------------------------------- helpers

    /** @return int[] raw axis values */
    private function axisVals(?array $a): array
    {
        if (! $a) {
            return [];
        }
        $v = [];
        for ($i = 0; $i < $a['n']; $i++) {
            $v[] = $a['w'] === 2 ? $this->bin->u16($a['vals'] + 2 * $i) : $this->bin->u8($a['vals'] + $i);
        }

        return $v;
    }

    /** @return int[] raw cell values (signedness of the table) */
    private function cells(array $t, ?bool $signed = null): array
    {
        $signed ??= $t['signed'];
        $n = $t['rows'] * $t['cols'];
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            $o = $t['data'] + $i * $t['w'];
            $v[] = $t['w'] === 2 ? ($signed ? $this->bin->s16($o) : $this->bin->u16($o)) : ($signed ? $this->bin->s8($o) : $this->bin->u8($o));
        }

        return $v;
    }

    private static function avg(array $v): float
    {
        return $v ? array_sum($v) / count($v) : 0.0;
    }

    /** Share of rows (or columns) whose cells are non-decreasing along the given direction. */
    private function monotonicShare(array $t, bool $alongCols): float
    {
        $c = $this->cells($t);
        $ok = 0;
        $lines = $alongCols ? $t['rows'] : $t['cols'];
        $len = $alongCols ? $t['cols'] : $t['rows'];
        for ($l = 0; $l < $lines; $l++) {
            $good = true;
            for ($i = 1; $i < $len; $i++) {
                $a = $alongCols ? $c[$l * $t['cols'] + $i - 1] : $c[($i - 1) * $t['cols'] + $l];
                $b = $alongCols ? $c[$l * $t['cols'] + $i] : $c[$i * $t['cols'] + $l];
                if ($b < $a) {
                    $good = false;
                    break;
                }
            }
            $ok += $good ? 1 : 0;
        }

        return $lines ? $ok / $lines : 0;
    }

    private function isRpm(?array $a): bool
    {
        return $a && $a['in'] !== null && ($a['in'] === $this->nmotW || $a['in'] === $this->nmotB);
    }

    private function isLoad(?array $a): bool
    {
        return $a && $a['in'] !== null && ($a['in'] === $this->rlW || $a['in'] === $this->rlB);
    }

    private function discoverVars(): void
    {
        $count = [];
        $axes = [];
        foreach ($this->scan->tables as $t) {
            $axes[] = $t['row'];
            if ($t['col']) {
                $axes[] = $t['col'];
            }
        }
        foreach ($this->scan->ivars as $a) {
            $axes[] = $a + ['vals' => $a['axis'] + $a['w']];
        }
        foreach ($axes as $a) {
            $v = $this->axisVals($a);
            if ($a['in'] !== null && $a['w'] === 2 && $v && end($v) >= 18000 && end($v) <= 32000 && $v[0] < 12000) {
                $count[$a['in']] = ($count[$a['in']] ?? 0) + 1;
            }
        }
        if ($count) {
            arsort($count);
            $this->nmotW = (string) array_key_first($count);
            // ME7 keeps the byte copy "nmot" two bytes below "nmot_w".
            $cand = sprintf('%X', hexdec($this->nmotW) - 2);
            foreach ($axes as $a) {
                $v = $this->axisVals($a);
                if ($a['in'] === $cand && $a['w'] === 1 && $v && end($v) >= 90) {
                    $this->nmotB = $cand;
                    break;
                }
            }
        }
        // Load: the column variable of the large signed byte maps (ignition) on engine speed rows.
        $lw = [];
        $lb = [];
        foreach ($this->scan->tables as $t) {
            if (! $t['col'] || $t['w'] !== 1 || $t['rows'] < 10 || $t['cols'] < 8) {
                continue;
            }
            if ($t['row']['in'] === $this->nmotW && $t['col']['w'] === 2) {
                $lw[$t['col']['in']] = ($lw[$t['col']['in']] ?? 0) + 1;
            }
            if ($this->nmotB && $t['row']['in'] === $this->nmotB && $t['col']['w'] === 1) {
                $lb[$t['col']['in']] = ($lb[$t['col']['in']] ?? 0) + 1;
            }
        }
        if ($lw) {
            arsort($lw);
            $this->rlW = (string) array_key_first($lw);
        }
        if ($lb) {
            arsort($lb);
            $this->rlB = (string) array_key_first($lb);
        }
        if ($this->rlW && ! $this->rlB) {
            // rl (byte) is usually 5..7 bytes below rl_w; accept any byte axis input there.
            foreach ($axes as $a) {
                $d = hexdec($this->rlW) - hexdec((string) $a['in']);
                if ($a['w'] === 1 && $a['in'] !== null && $d >= 4 && $d <= 8) {
                    $this->rlB = $a['in'];
                    break;
                }
            }
        }
    }

    /** Physical conversion for an axis from its input variable. */
    private function axis(?array $a, array $override = []): ?Axis
    {
        if (! $a) {
            return null;
        }
        [$factor, $unit, $name, $dec] = match (true) {
            $a['in'] !== null && $a['in'] === $this->nmotW => [0.25, 'rpm', 'nmot_w', 0],
            $a['in'] !== null && $a['in'] === $this->nmotB => [40.0, 'rpm', 'nmot', 0],
            $a['in'] !== null && $a['in'] === $this->rlW => [0.023438, '%', 'rl_w', 1],
            $a['in'] !== null && $a['in'] === $this->rlB => [0.75, '%', 'rl', 1],
            default => [1.0, '', $a['in'] !== null ? 'var_'.$a['in'] : 'axis', 0],
        };
        $factor = $override['factor'] ?? $factor;
        $unit = $override['unit'] ?? $unit;
        $name = $override['name'] ?? $name;
        $dec = $override['decimals'] ?? $dec;

        return new Axis($a['n'], $a['vals'], $a['w'] === 2 ? 'u16' : 'u8', false, $factor, $override['offset'] ?? 0.0, $unit, $name, null, $dec);
    }

    private function def(string $key, array $t, string $name, string $cat, string $desc, float $factor, string $unit, float $conf, array $opt = []): MapDef
    {
        $signed = $opt['signed'] ?? $t['signed'];
        $dtype = ($signed ? 's' : 'u').($t['w'] * 8);
        if (! empty($t['hooked'])) {
            $conf -= 0.1;
            $desc .= ' Code reference sits behind a hook call (patched file, e.g. multimap) - this is the original/first map.';
        }
        $curve = $t['col'] === null;
        $m = new MapDef(
            $name, $t['data'], $curve ? 1 : $t['rows'], $curve ? $t['rows'] : $t['cols'], $dtype, false,
            $factor, $opt['offset'] ?? 0.0, $unit, $cat, $desc, 'pattern', $conf, false, $opt['decimals'] ?? 2,
        );
        if ($curve) {
            $m->x = $this->axis($t['row'], $opt['y'] ?? []);
        } else {
            $m->y = $this->axis($t['row'], $opt['y'] ?? []);
            $m->x = $this->axis($t['col'], $opt['x'] ?? []);
        }
        $this->named[$key] = $m;

        return $m;
    }

    private function generic(array $t): MapDef
    {
        $cpu = 0x800000 + ($t['header'] ?? $t['data']);
        $name = sprintf('%s_%06X', $t['kind'] === 'KFS' ? 'KF' : $t['kind'], $cpu);
        $curve = $t['col'] === null;
        $desc = sprintf(
            '%s via lookup routine %s; %s. Unidentified.',
            $curve ? 'Curve' : 'Map',
            $t['fn'],
            $curve ? 'x = '.$this->axisLabel($t['row']) : 'rows = '.$this->axisLabel($t['row']).', cols = '.$this->axisLabel($t['col'])
        );
        $m = new MapDef(
            $name, $t['data'], $curve ? 1 : $t['rows'], $curve ? $t['rows'] : $t['cols'],
            ($t['signed'] ? 's' : 'u').($t['w'] * 8), false, 1.0, 0.0, '', 'Other', $desc, 'pattern', 0.25, false, 0,
        );
        if ($curve) {
            $m->x = $this->axis($t['row']);
        } else {
            $m->y = $this->axis($t['row']);
            $m->x = $this->axis($t['col']);
        }

        return $m;
    }

    private function axisLabel(array $a): string
    {
        return match (true) {
            $a['in'] !== null && $a['in'] === $this->nmotW => 'nmot_w',
            $a['in'] !== null && $a['in'] === $this->nmotB => 'nmot',
            $a['in'] !== null && $a['in'] === $this->rlW => 'rl_w',
            $a['in'] !== null && $a['in'] === $this->rlB => 'rl',
            default => 'RAM '.($a['in'] ?? '?'),
        };
    }

    /** Unnamed tables matching a predicate, in address order. */
    private function find(callable $fn): array
    {
        $r = [];
        foreach ($this->scan->tables as $k => $t) {
            if (! isset($this->named[$k]) && $fn($t)) {
                $r[$k] = $t;
            }
        }
        uasort($r, fn ($a, $b) => $a['data'] <=> $b['data']);

        return $r;
    }

    // ---------------------------------------------------------------- ignition

    private function ignition(): void
    {
        // Signed byte maps over (nmot, rl). Signedness is judged from the values, because hook
        // routines in patched files hide the original lookup routine.
        $ign = $this->find(fn ($t) => $t['col'] && $t['w'] === 1 && $t['rows'] >= 10 && $t['cols'] >= 8
            && $this->isRpm($t['row']) && $this->isLoad($t['col']));
        $groups = [];
        foreach ($ign as $k => $t) {
            $c = $this->cells($t, true);
            $avg = self::avg($c);
            if ($avg >= 12 && $avg <= 70 && max($c) <= 110 && min($c) >= -40) {
                $g = $t['row']['vals'].':'.$t['col']['vals'];
                $groups[$g]['maps'][$k] = $t;
                $groups[$g]['avg'][] = $avg;
            }
        }
        if (! $groups) {
            return;
        }
        uasort($groups, fn ($a, $b) => self::avg($b['avg']) <=> self::avg($a['avg']));
        $groups = array_values($groups);
        $zw = $groups[count($groups) >= 2 ? 1 : 0];
        $op = count($groups) >= 2 ? $groups[0] : null;
        // KFZWOP sits clearly above KFZW; otherwise the order is not trustworthy.
        if ($op && self::avg($op['avg']) < self::avg($zw['avg']) + 3) {
            $op = null;
        }
        // Only one group (KFZW lookups patched away): VAG KFZWOP uses an 11-point load axis, KFZW 12.
        if (count($groups) === 1 && reset($zw['maps'])['cols'] === 11) {
            $op = $zw;
            $zw = ['maps' => []];
        }

        $keys = array_keys($zw['maps']);
        $set = 1;
        for ($i = 0; $i < count($keys); $i++) {
            $a = $zw['maps'][$keys[$i]];
            $sfx = $set > 1 ? " (set $set)" : '';
            $conf = count($groups) >= 2 ? 0.9 : 0.75;
            $this->def($keys[$i], $a, 'KFZW'.$sfx, 'Ignition', 'Basic ignition timing map (zwbas), engine speed x load. Variant 1 (e.g. cam/flap inactive).', 0.75, '°KW', $conf, ['signed' => true, 'decimals' => 1]);
            $b = $zw['maps'][$keys[$i + 1] ?? ''] ?? null;
            if ($b && $b['data'] === $a['data'] + $a['rows'] * $a['cols']) {
                $this->def($keys[$i + 1], $b, 'KFZW2'.$sfx, 'Ignition', 'Basic ignition timing map, variant 2 (e.g. cam adjustment active). Stored right after KFZW.', 0.75, '°KW', $conf, ['signed' => true, 'decimals' => 1]);
                $i++;
            }
            $set++;
        }
        if ($op) {
            $n = 1;
            foreach ($op['maps'] as $k => $t) {
                $this->def($k, $t, 'KFZWOP'.($n > 1 ? $n : ''), 'Ignition', 'Optimum ignition angle (torque model reference). Keep consistent with KFZW when changing timing.', 0.75, '°KW', 0.8, ['signed' => true, 'decimals' => 1]);
                $n++;
            }
        }
    }

    // ---------------------------------------------------------------- torque

    private function torque(): void
    {
        // KFMIRL: rows nmot_w, columns torque request mi (0..100 %), cells rl (%), rising along mi.
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['w'] !== 2 || $t['signed'] || $t['rows'] < 8 || $t['cols'] < 8 || $t['row']['in'] !== $this->nmotW || $t['col']['w'] !== 2) {
                return false;
            }
            $cv = $this->axisVals($t['col']);
            $c = $this->cells($t);

            return $cv[0] <= 3000 && end($cv) >= 45000 && max($c) >= 2500 && max($c) <= 12000 && $this->monotonicShare($t, true) >= 0.7;
        });
        uasort($cand, fn ($a, $b) => $b['rows'] * $b['cols'] <=> $a['rows'] * $a['cols']);
        if ($k = array_key_first($cand)) {
            $this->def($k, $cand[$k], 'KFMIRL', 'Torque', 'Target load (rlsol) from requested indicated torque (mi). Raise the high-torque columns to allow more load/boost.', 0.023438, '%', 0.85, [
                'x' => ['factor' => 0.0015259, 'unit' => '%', 'name' => 'mi (torque request)', 'decimals' => 1],
                'decimals' => 1,
            ]);
        }

        // KFPED / KFPEDR: pedal position (rows, 0..100 %) x engine speed -> driver torque request (0..100 % = 32768).
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['w'] !== 2 || $t['rows'] < 8 || $t['cols'] < 6 || $t['row']['w'] !== 2 || ! $this->isRpm($t['col'])) {
                return false;
            }
            $rv = $this->axisVals($t['row']);
            $c = $this->cells($t, false);

            return $rv[0] <= 700 && end($rv) >= 60000 && min($c) <= 2000 && max($c) >= 28000 && max($c) <= 34000
                && $this->monotonicShare($t, false) >= 0.8;
        });
        $names = ['KFPED', 'KFPEDR'];
        $i = 0;
        foreach ($cand as $k => $t) {
            $name = $names[$i] ?? 'KFPED ('.($i + 1).')';
            $this->def($k, $t, $name, 'Torque', ($i === 1 ? 'Pedal map, second variant (in VAG files usually reverse gear / alternative mode). ' : 'Driver pedal map: ')
                .'pedal position x engine speed -> relative torque request.', 0.0030518, '%', $i < 2 ? 0.6 : 0.45, [
                    'signed' => false,
                    'y' => ['factor' => 0.0015259, 'unit' => '%', 'name' => 'wped (pedal)', 'decimals' => 1],
                    'decimals' => 1,
                ]);
            $i++;
        }
    }

    // ---------------------------------------------------------------- boost

    private function boost(): void
    {
        // LDRXN / LDRXNZK: two adjacent word curves over nmot_w holding max. load (%).
        // (signedness of the curve routine is irrelevant here, values are positive either way)
        $kl = $this->find(fn ($t) => ! $t['col'] && $t['w'] === 2 && $t['row']['in'] === $this->nmotW
            && $t['rows'] >= 10 && $t['rows'] <= 20 && min($this->cells($t, false)) >= 1500 && max($this->cells($t, false)) <= 10000);
        $byHeader = [];
        foreach ($kl as $k => $t) {
            $byHeader[$t['header'] ?? $t['row']['axis']] = $k;
        }
        $found = false;
        foreach ($kl as $k => $t) {
            $h = $t['header'] ?? $t['row']['axis'];
            $next = $h + 2 + 4 * $t['rows'];
            // LDRXNZK follows directly (same point count; axis copy may differ slightly).
            $k2 = $byHeader[$next] ?? $byHeader[$next + 2] ?? null;
            if ($k2 !== null && $kl[$k2]['rows'] === $t['rows']) {
                $this->ldrxn($k, $t, $k2, $kl[$k2], 0.85);
                $found = true;
                break;
            }
        }
        if (! $found) {
            $this->ldrxnByStructure();
        }

        // KFLDRL (candidate): duty-cycle linearisation, rows duty (0..100 %) x engine speed, output duty rising with input.
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['w'] !== 2 || $t['rows'] < 8 || $t['row']['w'] !== 2 || $t['col']['in'] !== $this->nmotW) {
                return false;
            }
            $rv = $this->axisVals($t['row']);
            $c = $this->cells($t, false);

            return $rv[0] === 0 && (end($rv) === 32768 || end($rv) >= 62000) && max($c) >= 50000 && $this->monotonicShare($t, false) >= 0.7;
        });
        if ($k = array_key_first($cand)) {
            $duty = $this->axisVals($cand[$k]['row']);
            $this->def($k, $cand[$k], 'KFLDRL', 'Boost', 'Wastegate duty-cycle linearisation (ldtvm -> ldtv) over engine speed. Identified by shape only - verify.', 0.0015259, '%', 0.45, [
                'signed' => false,
                'y' => ['factor' => end($duty) === 32768 ? 0.0030518 : 0.0015259, 'unit' => '%', 'name' => 'ldtvm (duty in)', 'decimals' => 1],
                'decimals' => 1,
            ]);
        }
    }

    private function ldrxn(string $k1, array $t1, ?string $k2, ?array $t2, float $conf): void
    {
        $this->def($k1, $t1, 'LDRXN', 'Boost', 'Maximum specified load (rlmax) over engine speed - main load/boost ceiling.', 0.023438, '%', $conf, ['decimals' => 1, 'signed' => false]);
        if ($t2) {
            $this->def($k2, $t2, 'LDRXNZK', 'Boost', 'Maximum specified load when knock control is active (knock retard).', 0.023438, '%', $conf, ['decimals' => 1, 'signed' => false]);
        }
    }

    /**
     * LDRXN by layout when the code reference is gone (e.g. patched multimap files):
     * [n][n x nmot_w][n x rl] immediately followed by the same axis again (LDRXNZK).
     */
    private function ldrxnByStructure(): void
    {
        $b = $this->bin;
        $end = min($b->size, 0x40000) - 140;
        for ($o = 0x10000; $o < $end; $o += 2) {
            $n = $b->u16($o);
            if ($n < 10 || $n > 20) {
                continue;
            }
            $prev = -1;
            for ($i = 0; $i < $n; $i++) {
                $v = $b->u16($o + 2 + 2 * $i);
                if ($v <= $prev) {
                    continue 2;
                }
                $prev = $v;
            }
            if ($b->u16($o + 2) < 2000 || $prev < 20000 || $prev > 30000) {
                continue;
            }
            $data = $o + 2 + 2 * $n;
            for ($i = 0; $i < $n; $i++) {
                $v = $b->u16($data + 2 * $i);
                if ($v < 1500 || $v > 10000) {
                    continue 2;
                }
            }
            foreach ([0, 2] as $pad) {
                $o2 = $data + 2 * $n + $pad;
                $sameAxis = $b->u16($o2) === $n && abs($b->u16($o2 + 2) - $b->u16($o + 2)) <= 800
                    && abs($b->u16($o2 + 2 * $n) - $prev) <= 800;
                if ($sameAxis) {
                    $mk = fn ($h) => ['kind' => 'KL', 'data' => $h + 2 + 2 * $n, 'header' => $h, 'rows' => $n, 'cols' => 1, 'w' => 2, 'signed' => false,
                        'row' => ['axis' => $h, 'vals' => $h + 2, 'n' => $n, 'w' => 2, 'in' => $this->nmotW], 'col' => null];
                    $this->ldrxn('S:'.$o, $mk($o), 'S:'.$o2, $mk($o2), 0.55);
                    $this->named['S:'.$o]->description .= ' Located by data layout (no code reference found).';

                    return;
                }
            }
        }
    }

    // ---------------------------------------------------------------- fuel / air

    private function fuel(): void
    {
        // LAMFA: byte map over engine speed x driver torque request, lambda around 1.0 (128).
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['w'] !== 1 || $t['signed'] || ($t['rows'] * $t['cols']) < 24) {
                return false;
            }
            $c = $this->cells($t);
            $rpmRow = $this->isRpm($t['row']) && ! $this->isLoad($t['col']) && ! $this->isRpm($t['col']);

            return $rpmRow && min($c) >= 90 && max($c) <= 135 && self::avg($c) <= 129 && $t['kind'] === 'KFS';
        });
        if ($k = array_key_first($cand)) {
            $this->def($k, $cand[$k], 'LAMFA', 'Fuel', 'Driver-requested lambda (lamfa) over engine speed x requested torque. 1.0 = stoich; lower = richer.', 0.0078125, 'λ', 0.7, [
                'x' => ['factor' => 0.003052, 'unit' => '%', 'name' => 'mrfa (torque request)', 'decimals' => 1],
                'decimals' => 3,
            ]);
        }

        // KFKHFM: MAF correction around 1.0 (128) over nmot x rl (often flat 128 in stock files).
        // The largest such byte map with its own axes wins (typically 14x14).
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['w'] !== 1 || $t['signed'] || $t['rows'] < 10 || $t['cols'] < 10 || ! $this->isRpm($t['row']) || ! $this->isLoad($t['col'])) {
                return false;
            }
            $c = $this->cells($t);

            return min($c) >= 64 && max($c) <= 200 && abs(self::avg($c) - 128) < 16;
        });
        uasort($cand, fn ($a, $b) => [$b['kind'] === 'KF', $b['rows'] * $b['cols']] <=> [$a['kind'] === 'KF', $a['rows'] * $a['cols']]);
        if ($k = array_key_first($cand)) {
            $this->def($k, $cand[$k], 'KFKHFM', 'Airflow', 'MAF sensor correction factor over engine speed x load (1.0 = no correction).', 0.0078125, '-', 0.6, ['decimals' => 3]);
        }

        // KFLBTS: component-protection lambda, a group map with LOAD rows x engine-speed columns
        // (ME7.5 SRL12GKUW x SNM16GKUB), byte lambda values around 1.0.
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['kind'] === 'KF' || $t['w'] !== 1 || $t['rows'] < 8 || $t['cols'] < 8 || ! $this->isLoad($t['row']) || ! $this->isRpm($t['col'])) {
                return false;
            }
            $c = $this->cells($t, false);

            return min($c) >= 64 && max($c) <= 200 && abs(self::avg($c) - 128) < 30;
        });
        uasort($cand, fn ($a, $b) => $b['rows'] * $b['cols'] <=> $a['rows'] * $a['cols']);
        if ($k = array_key_first($cand)) {
            $this->def($k, $cand[$k], 'KFLBTS', 'Fuel', 'Lambda for component protection (exhaust temperature), load x engine speed. Identified by shape - verify.', 0.0078125, 'λ', 0.5, ['decimals' => 3, 'signed' => false]);
        }

        $this->mlhfm();
        $this->krkte();
    }

    /** MLHFM: 512-word MAF linearisation, read as "shl r4,#1 ; mov r5,[r4+#MLHFM]". */
    private function mlhfm(): void
    {
        if (! preg_match_all('/\x5C\x14\xD4([\x04\x14\x24\x34\x44\x54\x64\x74\x84\x94\xA4\xB4\xC4\xD4\xE4\xF4])(..)/s', $this->bin->data, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($m[2] as [$raw, $at]) {
            $o = $this->scan->near(unpack('v', $raw)[1]);
            if ($o === null || $o + 1024 > $this->bin->size) {
                continue;
            }
            $drops = 0;
            $prev = -1;
            for ($i = 0; $i < 512; $i++) {
                $v = $this->bin->u16($o + 2 * $i);
                $drops += $v < $prev ? 1 : 0;
                $prev = $v;
            }
            if ($drops <= 2 && $prev > 5000) {
                $this->extra[] = new MapDef('MLHFM', $o, 1, 512, 'u16', false, 0.1, 0.0, 'kg/h', 'Airflow',
                    'MAF linearisation: 512 points over sensor voltage (0..5 V). Rescale for a different MAF housing.', 'pattern', 0.85, false, 1);

                return;
            }
        }
    }

    /** KRKTE: injector constant, loaded right before the rk -> te scaling ("shr r4,#4 ; mov r5,#0 ; ..."). */
    private function krkte(): void
    {
        $patterns = [
            ['/(?:\xD7[\x40\x50](..))?\xF2\xF4(..)\x7C\x44\xE0\x05\x70\x55/s', 'u16', 0.000111],
            ['/\xD7\x40(..)\xC2[\xF0-\xFF](..)\xE0\x05\x70\x55/s', 'u8', 0.002133],
        ];
        foreach ($patterns as [$re, $type, $factor]) {
            if (preg_match($re, $this->bin->data, $m)) {
                $page = $m[1] !== '' ? unpack('v', $m[1])[1] & 0x3FF : null;
                $o = $this->scan->near(unpack('v', $m[2])[1], $page);
                if ($o !== null && $o < 0x80000) {
                    $this->extra[] = new MapDef('KRKTE', $o, 1, 1, $type, false, $factor, 0.0, 'ms/%', 'Fuel',
                        'Injector constant (rk -> injection time). Scale inversely with injector flow when changing injectors.', 'pattern', 0.75, false, 6);

                    return;
                }
            }
        }
    }

    // ---------------------------------------------------------------- limiters

    /**
     * Engine-speed limit constants: words compared against nmot_w ("mov rX,nmot_w ; cmp rX,CONST")
     * with values 5500..8500 rpm. ME7 has several (NMAX, NMAXGA ...), so these are candidates.
     */
    private function limiters(): void
    {
        if (! $this->nmotW) {
            return;
        }
        $nm = hexdec($this->nmotW);
        if (! preg_match_all('/\xF2([\xF0-\xFF])'.preg_quote(pack('v', $nm), '/').'/s', $this->bin->data, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $hits = [];
        foreach ($m[0] as [$x, $at]) {
            if ($at % 2) {
                continue;
            }
            $reg = ord($x[1]);
            $q = $at + 4;
            $page = null;
            for ($k = 0; $k < 3 && $q + 4 <= $this->bin->size; $k++) {
                $op = $this->bin->u8($q);
                if ($op === 0xD7) {
                    $page = $this->bin->u16($q + 2) & 0x3FF;
                    $q += 4;

                    continue;
                }
                if ($op === 0x42 && $this->bin->u8($q + 1) === $reg) {
                    $f = $this->scan->near($this->bin->u16($q + 2), $page);
                    if ($f !== null && $f >= 0x10000 && $f < 0x40000) {
                        $rpm = $this->bin->u16($f) / 4;
                        if ($rpm >= 5500 && $rpm <= 8500) {
                            $hits[$f] = $rpm;
                        }
                    }
                    break;
                }
                $q += C167::len($op);
            }
        }
        ksort($hits);
        foreach ($hits as $f => $rpm) {
            $this->extra[] = new MapDef(count($hits) === 1 ? 'NMAX' : sprintf('NMAX? (%d rpm)', $rpm), $f, 1, 1, 'u16', false, 0.25, 0.0, 'rpm', 'Limiters',
                'Engine-speed limit constant compared directly against nmot_w. ME7 has several such limits (NMAX, gear/vehicle-speed dependent); verify before editing.',
                'pattern', count($hits) === 1 ? 0.5 : 0.35, false, 0);
        }
    }
}
