<?php

namespace App\Ecu\Diff;

use App\Ecu\Bin;
use App\Ecu\Dyno\EngineSpec;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapRoles;
use App\Ecu\Maps\MapValues;
use App\Ecu\Tuning\InjectionCheck;

/**
 * Rule-of-thumb review of a modified file against its original: flags changes that are risky for
 * the engine (far more fuel or boost than a stock engine takes, values at the storage limit) and
 * signs of a badly made tune (a limit raised while another still caps it, spikes, moved axes,
 * wrong checksums). These are heuristics, not a substitute for a datalog.
 */
class TuneReview
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** @var array<int, array{severity: string, title: string, detail: string, mapIds: string[]}> */
    private array $findings = [];

    /**
     * @param  MapDef[]  $maps
     * @param  array<string, mixed>  $diff  BinDiff::compare() result
     * @param  array<string, mixed>  $checksum  Analyzer::checksumReport() of the modified file
     * @param  array<string, string>  $overrides  role choices of the user
     * @return array{verdict: string, findings: array<int, array{severity: string, title: string, detail: string, mapIds: string[]}>}
     */
    public function review(array $maps, EngineSpec $spec, Bin $original, Bin $modified, array $diff, array $checksum, array $overrides = []): array
    {
        $this->findings = [];
        if ($diff['changedBytes'] === 0) {
            return ['verdict' => 'none', 'findings' => []];
        }

        $this->mapQuality($diff);
        if ($spec->diesel) {
            $this->dieselLimits($maps, $original, $modified, $overrides);
        } else {
            $this->petrolLimits($maps, $original, $modified, $diff, $overrides);
        }
        $this->unmapped($diff);
        $this->checksum($checksum);

        $order = [self::DANGER => 0, self::WARNING => 1, self::INFO => 2];
        usort($this->findings, fn (array $a, array $b): int => $order[$a['severity']] <=> $order[$b['severity']]);
        $severities = array_column($this->findings, 'severity');

        return [
            'verdict' => in_array(self::DANGER, $severities, true) ? 'risky' : (in_array(self::WARNING, $severities, true) ? 'check' : 'clean'),
            'findings' => $this->findings,
        ];
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function mapQuality(array $diff): void
    {
        foreach ($diff['maps'] as $m) {
            if ($m['clamped']) {
                $this->add(self::DANGER, "{$m['name']}: values at the storage limit",
                    'Some cells sit at the largest value the map can store. That is usually a typing or scaling mistake, not a real target.', [$m['id']]);
            }
            if ($m['spike']) {
                $this->add(self::WARNING, "{$m['name']}: spike at row {$m['spike']['row']}, column {$m['spike']['col']}",
                    'One cell jumps far away from its neighbours. The ECU interpolates between cells, so a spike makes the engine behave abruptly around that point.', [$m['id']]);
            }
            if ($m['axisChanged']) {
                $this->add(self::WARNING, "{$m['name']}: axis breakpoints changed",
                    'Changing an axis moves where every cell applies, and other maps sharing the axis change with it. Make sure that was intended.', [$m['id']]);
            }
        }
    }

    /**
     * @param  MapDef[]  $maps
     * @param  array<string, string>  $overrides
     */
    private function dieselLimits(array $maps, Bin $original, Bin $modified, array $overrides): void
    {
        $peak = fn (string $role, Bin $bin): ?float => $this->roleMax($maps, $modified, $role, $overrides, $bin);
        $ids = fn (string $role): array => array_map(fn (MapDef $m): string => $m->id, MapRoles::group($maps, $modified, $role, $overrides));

        $limitsBefore = array_filter(['torqueLimit' => $peak('torqueLimit', $original), 'fullLoadLimit' => $peak('fullLoadLimit', $original), 'wish' => $peak('wish', $original)], fn (?float $v): bool => $v !== null);
        $limitsAfter = array_filter(['torqueLimit' => $peak('torqueLimit', $modified), 'fullLoadLimit' => $peak('fullLoadLimit', $modified), 'wish' => $peak('wish', $modified)], fn (?float $v): bool => $v !== null);
        if (! $limitsBefore) {
            return;
        }

        $fuelBefore = min($limitsBefore);
        $fuelAfter = min($limitsAfter);
        $fuelIncrease = $fuelBefore > 0 ? $fuelAfter / $fuelBefore - 1 : 0.0;
        $fuelIds = [...$ids('torqueLimit'), ...$ids('fullLoadLimit'), ...$ids('wish')];

        if ($fuelIncrease > 0.35) {
            $this->add(self::DANGER, sprintf('Full-load fuel raised by %d %%', round($fuelIncrease * 100)),
                sprintf('The effective fuel limit goes from %.1f to %.1f. That is far beyond a Stage 1 (about 10–20 %%) and needs uprated injectors, turbo, clutch and EGT monitoring.', $fuelBefore, $fuelAfter), $fuelIds);
        } elseif ($fuelIncrease > 0.25) {
            $this->add(self::WARNING, sprintf('Full-load fuel raised by %d %%', round($fuelIncrease * 100)),
                sprintf('The effective fuel limit goes from %.1f to %.1f, more than a typical Stage 1. Watch exhaust gas temperature and smoke.', $fuelBefore, $fuelAfter), $fuelIds);
        }

        if ($fuelIncrease > 0.02) {
            $duration = InjectionCheck::warning($maps, $modified, $fuelAfter);
            if ($duration !== null) {
                $this->add(self::WARNING, 'Fuel limit beyond the injection duration maps', $duration, $ids('duration'));
            }
        }

        // a limiter raised while the driver's wish still caps it: the change does nothing
        $limiterAfter = min(array_intersect_key($limitsAfter, ['torqueLimit' => 1, 'fullLoadLimit' => 1]) ?: [INF]);
        $limiterBefore = min(array_intersect_key($limitsBefore, ['torqueLimit' => 1, 'fullLoadLimit' => 1]) ?: [INF]);
        if (isset($limitsAfter['wish']) && $limiterAfter !== INF && $limiterAfter > $limiterBefore * 1.05 && $limitsAfter['wish'] < $limiterAfter * 0.98) {
            $this->add(self::WARNING, "Driver's wish caps the raised torque limit",
                sprintf("The torque limit now allows %.1f but the driver's wish asks for at most %.1f, so part of the increase is never used.", $limiterAfter, $limitsAfter['wish']), $ids('wish'));
        }
        if (isset($limitsAfter['wish']) && $limiterAfter !== INF && $limitsAfter['wish'] > $limitsBefore['wish'] * 1.1 && $limiterAfter <= $limiterBefore * 1.01) {
            $this->add(self::INFO, "Driver's wish raised but the limiters are not",
                'Full-load fuel stays the same; only the pedal response gets sharper.', $ids('wish'));
        }

        $this->smoke($maps, $original, $modified, $overrides, $fuelIncrease);
        $this->boost($maps, $original, $modified, $overrides, $fuelIncrease);
    }

    /**
     * @param  MapDef[]  $maps
     * @param  array<string, string>  $overrides
     */
    private function smoke(array $maps, Bin $original, Bin $modified, array $overrides, float $fuelIncrease): void
    {
        $smoke = MapRoles::assign($maps, $modified, ['smoke'], $overrides)['smoke'] ?? null;
        if ($smoke === null) {
            return;
        }
        $before = new MapValues($smoke, $original);
        $after = new MapValues($smoke, $modified);
        $increase = max(array_merge(...$before->grid)) > 0 ? max(array_merge(...$after->grid)) / max(array_merge(...$before->grid)) - 1 : 0.0;

        if ($fuelIncrease > 0.1 && $increase < 0.02) {
            $this->add(self::WARNING, 'Smoke limiter not adjusted',
                'Fuel limits went up but the smoke limiter did not, so it now caps fuel wherever air is short and much of the gain is lost.', [$smoke->id]);
        }

        // fuel added at the lowest air mass column: that is spool-up, where it only makes smoke and heat
        $lowAir = [];
        $rpmOnX = $after->rpmOnX();
        $count = $rpmOnX ? $smoke->cols : $smoke->rows;
        for ($i = 0; $i < $count; $i++) {
            [$r, $c] = $rpmOnX ? [0, $i] : [$i, 0];
            if ($before->grid[$r][$c] > 0) {
                $lowAir[] = $after->grid[$r][$c] / $before->grid[$r][$c] - 1;
            }
        }
        if ($lowAir && max($lowAir) > 0.2) {
            $this->add(self::WARNING, 'Smoke limit raised at low air mass',
                sprintf('The smoke limit at the lowest air mass goes up by as much as %d %%. That is the spool-up region: expect black smoke and exhaust temperature spikes.', round(max($lowAir) * 100)), [$smoke->id]);
        }
    }

    /**
     * @param  MapDef[]  $maps
     * @param  array<string, string>  $overrides
     */
    private function boost(array $maps, Bin $original, Bin $modified, array $overrides, float $fuelIncrease): void
    {
        $roles = MapRoles::assign($maps, $modified, ['boost', 'boostLimit'], $overrides);
        if (! isset($roles['boost'])) {
            return;
        }
        $target = $roles['boost'];
        $toMbar = fn (string $unit, float $v): float => match (strtolower($unit)) {
            'bar' => $v * 1000, 'kpa' => $v * 10, default => $v,
        };
        $peakBefore = $toMbar($target->unit, max(array_merge(...(new MapValues($target, $original))->grid)));
        $peakAfter = $toMbar($target->unit, max(array_merge(...(new MapValues($target, $modified))->grid)));
        $boostIncrease = $peakBefore > 0 ? $peakAfter / $peakBefore - 1 : 0.0;

        if ($peakAfter > 3000) {
            $this->add(self::DANGER, sprintf('Boost target %d mbar', $peakAfter),
                'That is well past what stock turbos on these engines are built for (roughly 2500–2700 mbar absolute). Expect turbo overspeed, especially at altitude.', [$target->id]);
        } elseif ($peakAfter > 2700 && $peakAfter > $peakBefore) {
            $this->add(self::WARNING, sprintf('Boost target %d mbar', $peakAfter),
                'At or above the usual limit of a stock turbo. Check the turbo rating and datalog boost at high rpm.', [$target->id]);
        }
        if ($fuelIncrease > 0.15 && $boostIncrease < 0.02) {
            $this->add(self::WARNING, 'More fuel without more boost',
                sprintf('Fuel limits went up by %d %% but the boost target did not change. Without extra air that fuel turns into smoke and exhaust heat.', round($fuelIncrease * 100)), [$target->id]);
        }
        if ($boostIncrease > 0.1 && $fuelIncrease < 0.03) {
            $this->add(self::INFO, 'Boost raised without more fuel',
                'More air at the same fuel gives cooler exhaust but little extra power.', [$target->id]);
        }

        if (isset($roles['boostLimit'])) {
            $over = $this->targetAboveLimiter($target, $roles['boostLimit'], $modified);
            if ($over && ! $this->targetAboveLimiter($target, $roles['boostLimit'], $original)) {
                $this->add(self::WARNING, 'Boost target above the boost limiter',
                    sprintf('Between %d and %d rpm the target is higher than the limiter allows, so the ECU cuts it back (and may log overboost). Raise both together.', min($over), max($over)),
                    [$target->id, $roles['boostLimit']->id]);
            }
        }
    }

    /**
     * Engine speeds where the highest boost target exceeds the limiter at standard pressure.
     *
     * @return int[]
     */
    private function targetAboveLimiter(MapDef $target, MapDef $limiter, Bin $bin): array
    {
        $t = new MapValues($target, $bin);
        $l = new MapValues($limiter, $bin);
        $atm = match (strtolower((string) $l->otherAxisDef()?->unit)) {
            'bar' => 1.013, 'kpa' => 101.3, default => 1013.0,
        };
        $rpms = [];
        foreach ($t->rpmAxis() as $rpm) {
            $highest = max(array_map(fn (float $q): float => $t->atRpm($rpm, $q), $t->otherAxis() ?: [0.0]));
            if ($highest > $l->atRpm($rpm, $atm) + 20) {
                $rpms[] = (int) $rpm;
            }
        }

        return $rpms;
    }

    /**
     * @param  MapDef[]  $maps
     * @param  array<string, mixed>  $diff
     * @param  array<string, string>  $overrides
     */
    private function petrolLimits(array $maps, Bin $original, Bin $modified, array $diff, array $overrides): void
    {
        $ldrxn = MapRoles::assign($maps, $modified, ['ldrxn'], $overrides)['ldrxn'] ?? null;
        if ($ldrxn !== null) {
            $after = max(array_merge(...(new MapValues($ldrxn, $modified))->grid));
            $before = max(array_merge(...(new MapValues($ldrxn, $original))->grid));
            if ($after > 191 && $after > $before) {
                $this->add(self::WARNING, sprintf('Maximum load %d %%', $after),
                    'Above about 191 % the stock 2.5 bar MAP sensor runs out of range, so boost can no longer be measured or controlled.', [$ldrxn->id]);
            }
        }

        foreach ($diff['maps'] as $m) {
            if (preg_match('/^KFZW\d?$/i', $m['name']) && $m['maxIncrease'] > 2) {
                $this->add($m['maxIncrease'] > 5 ? self::DANGER : self::WARNING, sprintf('%s: ignition advanced by up to %.1f°', $m['name'], $m['maxIncrease']),
                    'More advance raises knock risk, more so together with more load. Datalog knock retard on every cylinder.', [$m['id']]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function unmapped(array $diff): void
    {
        $bytes = array_sum(array_column($diff['unmapped'], 'length'));
        if ($bytes === 0) {
            return;
        }
        $this->add($bytes > 256 ? self::WARNING : self::INFO, sprintf('%d bytes changed outside known maps', $bytes),
            sprintf('%d range(s) of changed bytes belong to no map in the definition. That can be a map the definition lacks, a code patch, or a mistake.', count($diff['unmapped'])), []);
    }

    /**
     * @param  array<string, mixed>  $checksum
     */
    private function checksum(array $checksum): void
    {
        if (($checksum['ok'] ?? null) !== false) {
            return;
        }
        if ($checksum['canFix'] ?? false) {
            $this->add(self::INFO, 'Checksums need correcting', 'BinBench corrects them when you save this version with checksum correction on.', []);
        } else {
            $this->add(self::DANGER, 'Checksums do not match', 'BinBench cannot correct them for this ECU. Flashing it as is can leave the ECU unable to start; correct them in your flashing tool.', []);
        }
    }

    /**
     * Highest value of all maps of a role in a buffer.
     *
     * @param  MapDef[]  $maps
     * @param  array<string, string>  $overrides
     */
    private function roleMax(array $maps, Bin $reference, string $role, array $overrides, Bin $bin): ?float
    {
        $group = MapRoles::group($maps, $reference, $role, $overrides);
        if (! $group) {
            return null;
        }

        return max(array_map(fn (MapDef $m): float => max(array_merge(...(new MapValues($m, $bin))->grid)), $group));
    }

    /**
     * @param  string[]  $mapIds
     */
    private function add(string $severity, string $title, string $detail, array $mapIds): void
    {
        $this->findings[] = ['severity' => $severity, 'title' => $title, 'detail' => $detail, 'mapIds' => array_values(array_unique($mapIds))];
    }
}
