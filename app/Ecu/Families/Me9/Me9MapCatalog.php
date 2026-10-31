<?php

namespace App\Ecu\Families\Me9;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Names the tables harvested by Me9LookupScanner.
 *
 * No fixed addresses: the engine-speed and load variables are discovered from the ignition
 * maps (byte axes nmot x rl for KFZW, word axes nmot_w x rl_w for KFZWOP, as on ME7), then the
 * other maps are recognised by their inputs, shared axes, value ranges and shape.
 */
class Me9MapCatalog
{
    private ?string $nmotW = null;

    private ?string $nmotB = null;

    private ?string $rlW = null;

    private ?string $rlB = null;

    /** @var array<int, MapDef> data offset => named map */
    private array $named = [];

    public function __construct(private Bin $bin, private Me9LookupScanner $scan) {}

    /** @return MapDef[] */
    public function build(bool $includeUnnamed = true): array
    {
        $this->discoverVars();
        $this->ignition();
        $this->torque();
        $this->boost();
        $this->fuel();

        $out = array_values($this->named);
        if ($includeUnnamed) {
            foreach ($this->scan->tables as $key => $t) {
                if (! isset($this->named[$key])) {
                    $out[] = $this->generic($t);
                }
            }
        }

        return $out;
    }

    /** @return array<string, ?string> */
    public function vars(): array
    {
        return ['nmot_w' => $this->nmotW, 'nmot' => $this->nmotB, 'rl_w' => $this->rlW, 'rl' => $this->rlB];
    }

    // ---------------------------------------------------------------- helpers

    /** @return int[] */
    private function axisVals(?array $a): array
    {
        if (! $a) {
            return [];
        }
        $size = Bin::typeSize($a['type']);
        $v = [];
        for ($i = 0; $i < $a['n']; $i++) {
            $v[] = $this->bin->read($a['vals'] + $i * $size, $a['type'], true);
        }

        return $v;
    }

    /** @return int[] */
    private function cells(array $t): array
    {
        $size = Bin::typeSize($t['type']);
        $v = [];
        for ($i = 0, $n = $t['rows'] * $t['cols']; $i < $n; $i++) {
            $v[] = $this->bin->read($t['data'] + $i * $size, $t['type'], true);
        }

        return $v;
    }

    private static function avg(array $v): float
    {
        return $v ? array_sum($v) / count($v) : 0.0;
    }

    /** Share of lines whose cells never fall along the columns (true) or along the rows (false). */
    private function monotonicShare(array $t, bool $alongCols): float
    {
        $c = $this->cells($t);
        $lines = $alongCols ? $t['rows'] : $t['cols'];
        $len = $alongCols ? $t['cols'] : $t['rows'];
        $ok = 0;
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

        return $lines ? $ok / $lines : 0.0;
    }

    private function in(?array $a): ?string
    {
        return $a['in'] ?? null;
    }

    private function isRpm(?array $a): bool
    {
        $in = $this->in($a);

        return $in !== null && ($in === $this->nmotW || $in === $this->nmotB);
    }

    private function isLoad(?array $a): bool
    {
        $in = $this->in($a);

        return $in !== null && ($in === $this->rlW || $in === $this->rlB);
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

        return $r;
    }

    private function discoverVars(): void
    {
        $count = [];
        foreach ($this->scan->tables as $t) {
            foreach ([$t['row'], $t['col']] as $a) {
                $v = $this->axisVals($a);
                if ($a && $a['in'] !== null && $a['inw'] === 2 && $v && end($v) >= 18000 && end($v) <= 32000 && $v[0] < 12000) {
                    $count[$a['in']] = ($count[$a['in']] ?? 0) + 1;
                }
            }
        }
        if ($count) {
            arsort($count);
            $this->nmotW = (string) array_key_first($count);
        }

        // Ignition-like signed byte maps: rows engine speed, columns load.
        $byteRows = [];
        $byteCols = [];
        $wordCols = [];
        foreach ($this->scan->tables as $t) {
            if (! $t['col'] || $t['type'] !== 's8' || $t['rows'] < 12 || $t['cols'] < 8 || $t['row']['in'] === null || $t['col']['in'] === null) {
                continue;
            }
            $avg = self::avg($this->cells($t));
            if ($avg < 12 || $avg > 70) {
                continue;
            }
            if ($t['row']['in'] === $this->nmotW && $t['col']['inw'] === 2) {
                $wordCols[$t['col']['in']] = ($wordCols[$t['col']['in']] ?? 0) + 1;
            } elseif ($t['row']['inw'] === 1 && $t['col']['inw'] === 1 && max($this->axisVals($t['row'])) <= 255) {
                $byteRows[$t['row']['in']] = ($byteRows[$t['row']['in']] ?? 0) + 1;
                $byteCols[$t['col']['in']] = ($byteCols[$t['col']['in']] ?? 0) + 1;
            }
        }
        foreach (['rlW' => $wordCols, 'nmotB' => $byteRows, 'rlB' => $byteCols] as $prop => $c) {
            if ($c) {
                arsort($c);
                $this->{$prop} = (string) array_key_first($c);
            }
        }
    }

    /** Physical conversion of an axis from its input variable. */
    private function axis(?array $a, array $override = []): ?Axis
    {
        if (! $a) {
            return null;
        }
        $in = $a['in'];
        [$factor, $unit, $name, $dec] = match (true) {
            $in !== null && $in === $this->nmotW => [0.25, 'rpm', 'nmot_w', 0],
            $in !== null && $in === $this->nmotB => [40.0, 'rpm', 'nmot', 0],
            $in !== null && $in === $this->rlW => [0.0234375, '%', 'rl_w', 1],
            $in !== null && $in === $this->rlB => [0.75, '%', 'rl', 1],
            default => [1.0, '', $in !== null ? 'RAM 0x'.$in : 'axis', 0],
        };

        return new Axis(
            $a['n'], $a['vals'], $a['type'], true, $override['factor'] ?? $factor, $override['offset'] ?? 0.0,
            $override['unit'] ?? $unit, $override['name'] ?? $name, null, $override['decimals'] ?? $dec,
        );
    }

    private function label(?array $a): string
    {
        $in = $this->in($a);

        return match (true) {
            $in !== null && $in === $this->nmotW => 'nmot_w',
            $in !== null && $in === $this->nmotB => 'nmot',
            $in !== null && $in === $this->rlW => 'rl_w',
            $in !== null && $in === $this->rlB => 'rl',
            default => 'RAM '.($in !== null ? '0x'.$in : '?'),
        };
    }

    private function def(int $key, array $t, string $name, string $cat, string $desc, float $factor, string $unit, float $conf, array $opt = []): MapDef
    {
        $curve = $t['col'] === null;
        $m = new MapDef(
            $name, $t['data'], $curve ? 1 : $t['rows'], $curve ? $t['rows'] : $t['cols'], $t['type'], true,
            $factor, $opt['offset'] ?? 0.0, $unit, $cat, $desc, 'pattern', $conf, false, $opt['decimals'] ?? 2,
        );
        if ($curve) {
            $m->x = $this->axis($t['row'], $opt['y'] ?? []);
        } else {
            $m->y = $this->axis($t['row'], $opt['y'] ?? []);
            $m->x = $this->axis($t['col'], $opt['x'] ?? []);
        }

        return $this->named[$key] = $m;
    }

    private function generic(array $t): MapDef
    {
        $curve = $t['col'] === null;
        $name = sprintf('%s_%06X', $curve ? 'KL' : 'KF', $t['data']);
        $desc = sprintf(
            '%s via lookup routine 0x%X (%s%s); %s. Unidentified.',
            $curve ? 'Curve' : 'Map', $t['fn'], $t['kind'], $t['step'] ? ', no interpolation' : '',
            $curve ? 'x = '.$this->label($t['row']) : 'rows = '.$this->label($t['row']).', cols = '.$this->label($t['col'])
        );
        $m = new MapDef(
            $name, $t['data'], $curve ? 1 : $t['rows'], $curve ? $t['rows'] : $t['cols'], $t['type'], true,
            1.0, 0.0, '', 'Other', $desc, 'pattern', 0.25, false, 0,
        );
        if ($curve) {
            $m->x = $this->axis($t['row']);
        } else {
            $m->y = $this->axis($t['row']);
            $m->x = $this->axis($t['col']);
        }

        return $m;
    }

    /**
     * Name pairs of maps stored back to back (variant 1 / variant 2) and further sets.
     *
     * @param  array<int, array>  $maps
     */
    private function nameSets(array $maps, string $base, string $desc1, string $desc2, float $conf): void
    {
        $keys = array_keys($maps);
        $set = 1;
        for ($i = 0; $i < count($keys); $i++) {
            $a = $maps[$keys[$i]];
            $sfx = $set > 1 ? " (set $set)" : '';
            $this->def($keys[$i], $a, $base.$sfx, 'Ignition', $desc1, 0.75, '°CA', $conf, ['decimals' => 1]);
            $b = $maps[$keys[$i + 1] ?? -1] ?? null;
            if ($b && $b['data'] === $a['data'] + $a['rows'] * $a['cols']) {
                $this->def($keys[$i + 1], $b, $base.'2'.$sfx, 'Ignition', $desc2, 0.75, '°CA', $conf, ['decimals' => 1]);
                $i++;
            }
            $set++;
        }
    }

    // ---------------------------------------------------------------- ignition

    private function ignition(): void
    {
        $plausible = function (array $t): bool {
            $c = $this->cells($t);
            $avg = self::avg($c);

            return $avg >= 12 && $avg <= 70 && max($c) <= 110 && min($c) >= -64;
        };
        // KFZW / KFZW2: byte axes nmot x rl.
        if ($this->nmotB && $this->rlB) {
            $maps = $this->find(fn ($t) => $t['col'] && $t['type'] === 's8' && $t['rows'] >= 10 && $t['cols'] >= 8
                && $this->in($t['row']) === $this->nmotB && $this->in($t['col']) === $this->rlB && $plausible($t));
            $this->nameSets($maps, 'KFZW',
                'Basic ignition timing map (zwbas), engine speed x load. ME9 blends the variants by camshaft position; change all sets consistently.',
                'Basic ignition timing map, variant 2 (other camshaft position), stored right after KFZW.', 0.75);
        }
        // KFZWOP: word axes nmot_w x rl_w (torque model reference).
        if ($this->nmotW && $this->rlW) {
            $maps = $this->find(fn ($t) => $t['col'] && $t['type'] === 's8' && $t['rows'] >= 10 && $t['cols'] >= 8
                && $this->in($t['row']) === $this->nmotW && $this->in($t['col']) === $this->rlW && $plausible($t));
            $this->nameSets($maps, 'KFZWOP',
                'Optimum ignition angle (torque model reference), engine speed x load. Keep consistent with KFZW when changing timing; ME9 blends several sets by camshaft position.',
                'Optimum ignition angle, variant 2 (other camshaft position).', 0.7);
        }
    }

    // ---------------------------------------------------------------- torque

    private function torque(): void
    {
        if (! $this->nmotW) {
            return;
        }
        // KFMIRL: engine speed x torque request mi (0..100 %) -> target load rl, rising along mi.
        $cand = [];
        foreach ($this->find(fn ($t) => $t['col'] && $t['type'] === 'u16' && $t['rows'] >= 8 && $t['cols'] >= 8) as $k => $t) {
            $rpmRows = $this->in($t['row']) === $this->nmotW;
            if (! $rpmRows && $this->in($t['col']) !== $this->nmotW) {
                continue;
            }
            $mi = $this->axisVals($rpmRows ? $t['col'] : $t['row']);
            $c = $this->cells($t);
            if ($mi[0] <= 3000 && end($mi) >= 45000 && max($c) >= 2500 && max($c) <= 16000 && $this->monotonicShare($t, $rpmRows) >= 0.7) {
                $cand[$k] = [$t, $rpmRows];
            }
        }
        uasort($cand, fn ($a, $b) => $b[0]['rows'] * $b[0]['cols'] <=> $a[0]['rows'] * $a[0]['cols']);
        if ($k = array_key_first($cand)) {
            [$t, $rpmRows] = $cand[$k];
            $mi = ['factor' => 0.0015259, 'unit' => '%', 'name' => 'mi (torque request)', 'decimals' => 1];
            $this->def($k, $t, 'KFMIRL', 'Torque', 'Target load (rlsol) from requested indicated torque (mi). Raise the high-torque cells to allow more load/boost.', 0.0234375, '%', 0.8,
                ['decimals' => 1] + ($rpmRows ? ['x' => $mi] : ['y' => $mi]));
        }

        // KFMIOP: optimum torque from load, on the KFZWOP axes, 0..100 % (65535).
        if ($this->rlW) {
            $cand = $this->find(function ($t) {
                if (! $t['col'] || $t['type'] !== 'u16' || $this->in($t['row']) !== $this->nmotW || $this->in($t['col']) !== $this->rlW) {
                    return false;
                }
                $c = $this->cells($t);

                return max($c) >= 45000 && $this->monotonicShare($t, true) >= 0.8;
            });
            if ($k = array_key_first($cand)) {
                $this->def($k, $cand[$k], 'KFMIOP', 'Torque', 'Optimum indicated torque from load (inverse of KFMIRL), engine speed x rl. Keep consistent with KFMIRL.', 0.0015259, '%', 0.7, ['decimals' => 1]);
            }
        }

        $this->pedal();
    }

    /** KFPED family: pedal (0..100 %) x engine speed -> relative torque request (100 % = 32768). */
    private function pedal(): void
    {
        $cand = [];
        foreach ($this->find(fn ($t) => $t['col'] && in_array($t['type'], ['u16', 's16'], true) && $t['rows'] >= 6 && $t['cols'] >= 6) as $k => $t) {
            $rpmCols = $this->in($t['col']) === $this->nmotW;
            if (! $rpmCols && $this->in($t['row']) !== $this->nmotW) {
                continue;
            }
            $ped = $this->axisVals($rpmCols ? $t['row'] : $t['col']);
            $c = $this->cells($t);
            if ($ped[0] <= 700 && end($ped) >= 60000 && min($c) <= 2000 && max($c) >= 28000 && max($c) <= 34000
                && $this->monotonicShare($t, ! $rpmCols) >= 0.8) {
                $cand[$k] = [$t, $rpmCols];
            }
        }
        if (! $cand) {
            return;
        }
        $reverse = null;
        foreach ($cand as $k => [$t]) {
            if ($this->gearSelected($t['refs'], 7)) {
                $reverse = $k;
            }
        }
        $first = null;
        foreach ($cand as $k => [$t]) {
            if ($k !== $reverse && ($first === null || min($t['refs']) < min($cand[$first][0]['refs']))) {
                $first = $k;
            }
        }
        $alternatives = count($cand) - ($first !== null ? 1 : 0) - ($reverse !== null ? 1 : 0);
        $n = 1;
        foreach ($cand as $k => [$t, $rpmCols]) {
            [$name, $desc, $conf] = match (true) {
                $k === $first => ['KFPED', 'Driver pedal map: pedal position x engine speed -> relative torque request.', 0.65],
                $k === $reverse => ['KFPEDR', 'Pedal map selected when the gear index is 7 (reverse).', 0.55],
                default => ['KFPED (alternative'.($alternatives > 1 ? ' '.$n++ : '').')', 'Alternative pedal map (blended or mode-dependent), pedal position x engine speed -> relative torque request.', 0.45],
            };
            $ped = ['factor' => 0.0015259, 'unit' => '%', 'name' => 'wped (pedal)', 'decimals' => 1];
            $this->def($k, $t, $name, 'Torque', $desc, 0.0030518, '%', $conf, ['decimals' => 1] + ($rpmCols ? ['y' => $ped] : ['x' => $ped]));
        }
    }

    /** Whether one of the call sites is guarded by "cmpwi rX,<gear>" a few instructions earlier. */
    private function gearSelected(array $refs, int $gear): bool
    {
        foreach ($refs as $at) {
            for ($k = 1; $k <= 8; $k++) {
                $w = $this->scan->word($at - 4 * $k);
                if (($w >> 26) === 11 && ($w & 0xFFFF) === $gear) {
                    return true;
                }
                if ($w === 0x4E800020 || (($w >> 26) === 18 && ($w & 1))) {
                    break;
                }
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- boost

    private function boost(): void
    {
        if (! $this->nmotW) {
            return;
        }
        // KFLDRL: wastegate duty linearisation, engine speed x duty (0..100 %) -> duty, close to identity.
        $best = null;
        foreach ($this->find(fn ($t) => $t['col'] && in_array($t['type'], ['u16', 's16'], true) && $t['rows'] >= 6 && $t['cols'] >= 6) as $k => $t) {
            $rpmRows = $this->in($t['row']) === $this->nmotW;
            if (! $rpmRows && $this->in($t['col']) !== $this->nmotW) {
                continue;
            }
            $duty = $this->axisVals($rpmRows ? $t['col'] : $t['row']);
            $full = end($duty);
            if ($duty[0] !== 0 || ! in_array($full, [20000, 32768, 65535], true)) {
                continue;
            }
            $c = $this->cells($t);
            if (min($c) < 0 || max($c) > $full || $this->monotonicShare($t, $rpmRows) < 0.8) {
                continue;
            }
            $same = 0;
            foreach ($c as $i => $v) {
                $d = $rpmRows ? $duty[$i % $t['cols']] : $duty[intdiv($i, $t['cols'])];
                $same += $v === $d ? 1 : 0;
            }
            if ($same / count($c) >= 0.3 && (! $best || $same > $best[2])) {
                $best = [$k, $rpmRows, $same, 100 / $full];
            }
        }
        if (! $best) {
            return;
        }
        [$k, $rpmRows, , $dutyFactor] = $best;
        $t = $this->scan->tables[$k];
        $duty = ['factor' => $dutyFactor, 'unit' => '%', 'name' => 'ldtvm (duty in)', 'decimals' => 1];
        $this->def($k, $t, 'KFLDRL', 'Boost', 'Wastegate duty-cycle linearisation (ldtvm -> ldtv) over engine speed. Identity where the actuator is linear.', $dutyFactor, '%', 0.65,
            ['decimals' => 1] + ($rpmRows ? ['x' => $duty] : ['y' => $duty]));

        // KFLDIMX: duty ceiling over engine speed x boost target, same duty scale, used next to KFLDRL.
        $full = (int) round(100 / $dutyFactor);
        $near = min($t['refs']);
        $cand = $this->find(function ($m) use ($full, $near) {
            if (! $m['col'] || ! in_array($m['type'], ['u16', 's16'], true) || $m['rows'] < 6 || $m['cols'] < 4
                || $this->in($m['row']) !== $this->nmotW || abs(min($m['refs']) - $near) > 0x2000) {
                return false;
            }
            $c = $this->cells($m);

            return min($c) > 0 && max($c) === $full && $this->monotonicShare($m, true) >= 0.8;
        });
        if ($k = array_key_first($cand)) {
            $this->def($k, $cand[$k], 'KFLDIMX', 'Boost', 'Wastegate duty limit / I-controller ceiling over engine speed x boost-pressure target (ME7 KFLDIMX equivalent). Identified by shape and code proximity to KFLDRL - verify; the column axis is unscaled.', $dutyFactor, '%', 0.4, ['decimals' => 1]);
        }
    }

    // ---------------------------------------------------------------- fuel

    private function fuel(): void
    {
        // LAMFA: byte lambda (1.0 = 128) over engine speed x driver torque request, richer at high torque.
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['type'] !== 'u8' || $t['rows'] * $t['cols'] < 24) {
                return false;
            }
            $rpmRows = $this->isRpm($t['row']);
            $other = $rpmRows ? $t['col'] : $t['row'];
            if ((! $rpmRows && ! $this->isRpm($t['col'])) || $other['in'] === null || $other['inw'] !== 2 || $this->isLoad($other) || $this->isRpm($other)) {
                return false;
            }
            $c = $this->cells($t);

            return min($c) >= 90 && min($c) < 124 && max($c) <= 135 && in_array(128, $c, true) && self::avg($c) <= 129;
        });
        uasort($cand, fn ($a, $b) => $b['rows'] * $b['cols'] <=> $a['rows'] * $a['cols']);
        if ($k = array_key_first($cand)) {
            $t = $cand[$k];
            $rpmRows = $this->isRpm($t['row']);
            $other = $this->axisVals($rpmRows ? $t['col'] : $t['row']);
            $mrfa = end($other) <= 32768 ? ['factor' => 0.0030518, 'unit' => '%', 'name' => 'torque request (mrfa)', 'decimals' => 1] : ['name' => 'torque request (mrfa)'];
            $this->def($k, $t, 'LAMFA', 'Fuel', 'Driver-requested lambda (lamfa) over engine speed x requested torque. 1.0 = stoich; lower = richer.', 0.0078125, 'λ', 0.55,
                ['decimals' => 3] + ($rpmRows ? ['x' => $mrfa] : ['y' => $mrfa]));
        }

        // KFLBTS: component-protection lambda over load x engine speed, below 1.0 at high load.
        $cand = $this->find(function ($t) {
            if (! $t['col'] || $t['type'] !== 'u8' || $t['rows'] * $t['cols'] < 64) {
                return false;
            }
            if (! (($this->isLoad($t['row']) && $this->isRpm($t['col'])) || ($this->isRpm($t['row']) && $this->isLoad($t['col'])))) {
                return false;
            }
            $c = $this->cells($t);

            return min($c) >= 64 && max($c) <= 130 && in_array(128, $c, true) && self::avg($c) < 127;
        });
        uasort($cand, fn ($a, $b) => $b['rows'] * $b['cols'] <=> $a['rows'] * $a['cols']);
        if ($k = array_key_first($cand)) {
            $this->def($k, $cand[$k], 'KFLBTS', 'Fuel', 'Lambda for component protection (exhaust temperature), load x engine speed. Identified by shape - verify.', 0.0078125, 'λ', 0.5, ['decimals' => 3]);
        }
    }
}
