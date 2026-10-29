<?php

namespace App\Ecu\Tuning;

use App\Ecu\Bin;
use App\Ecu\Dyno\DynoEstimator;
use App\Ecu\Dyno\EngineSpec;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapRoles;
use App\Ecu\Maps\MapValues;
use App\Ecu\Maps\MapWriter;

/**
 * Rule-based Stage 1 proposal: raises the full-load fuel and boost (diesel) or load (ME7) limits
 * by bounded percentages, fading the change in towards full load so part load stays stock.
 * Emission related maps (EGR, DPF, SCR, lambda) are never touched.
 */
class Stage1Planner
{
    private const ROLE_LABELS = [
        'wish' => "driver's wish",
        'torqueLimit' => 'torque limiter',
        'fullLoadLimit' => 'full-load fuel limit',
        'smoke' => 'smoke limiter',
        'boost' => 'boost pressure target',
        'boostLimit' => 'boost pressure limiter',
        'ldrxn' => 'maximum load (LDRXN)',
        'kfmirl' => 'torque to load map (KFMIRL)',
    ];

    /** Headroom kept between the boost target and the boost limiter, mbar. */
    private const BOOST_LIMIT_MARGIN = 100.0;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param  MapDef[]  $maps
     * @param  Bin  $original  the unmodified file, used as the dyno baseline
     * @param  Bin  $base  the buffer the proposal builds on (the editor's current state)
     * @param  array<string, string>  $overrides  role => map id chosen by the user, or MapRoles::NONE
     * @return array<string, mixed>
     */
    public function plan(array $maps, EngineSpec $spec, Bin $original, Bin $base, Stage1Options $options, array $overrides = []): array
    {
        $this->warnings = [];
        $detected = MapRoles::isTurbo($maps, $base, $spec->diesel);
        $turbo = $options->turbo ?? $detected ?? true;
        $wanted = $spec->diesel ? MapRoles::DIESEL : MapRoles::PETROL;
        $groups = [];
        foreach ($wanted as $role) {
            $groups[$role] = array_map(fn (MapDef $m): MapValues => new MapValues($m, $base), MapRoles::group($maps, $base, $role, $overrides));
        }

        $result = [
            'available' => false,
            'reason' => null,
            'options' => $options->toArray(),
            'proposals' => [],
            'warnings' => [],
            'notes' => $this->notes($spec, $turbo),
            'aspiration' => [
                'detected' => $detected === null ? null : ($detected ? 'turbo' : 'na'),
                'used' => $turbo ? 'turbo' : 'na',
            ],
            'roles' => MapRoles::describe($maps, $base, $wanted, $overrides),
            'dyno' => null,
        ];

        $proposals = match (true) {
            ! $turbo => $this->naturallyAspirated($groups, $options, $spec),
            $spec->diesel => $this->diesel($groups, $options),
            default => $this->petrol($groups, $options),
        };
        // a map matching two roles is changed once, by the first rule
        $proposals = array_values(array_intersect_key($proposals, array_unique(array_column($proposals, 'mapId'))));
        foreach ($proposals as $p) {
            if ($p['changedCells'] === 0) {
                $this->warnings[] = sprintf('%s was picked as the %s but no cell would change. Check its axes, or pick another map for this role.', $p['name'], self::ROLE_LABELS[$p['role']]);
            }
        }
        $proposals = array_values(array_filter($proposals, fn (array $p): bool => $p['changedCells'] > 0));
        if (! $proposals) {
            $result['reason'] = match (true) {
                ! $turbo => $spec->diesel
                    ? "No driver's wish map was found, so there is nothing to shape the response with."
                    : 'No pedal map (KFPED) was found, so there is nothing to shape the response with.',
                $spec->diesel => "No torque limiter or driver's wish map was found, so there is nothing to base a Stage 1 on.",
                default => 'LDRXN was not found, so there is nothing to base a Stage 1 on.',
            };

            return [...$result, 'warnings' => array_values(array_unique($this->warnings))];
        }

        if ($spec->diesel && $turbo) {
            $this->checkInjectionDuration($maps, $base, $proposals);
        }

        if ($spec->diesel && $turbo && ! $groups['torqueLimit'] && ! $groups['fullLoadLimit']) {
            $this->warnings[] = 'No torque limiter was found, so only the driver\'s wish changes. Pick the full-load torque limiter for this role if the file has one.';
        }

        $dyno = (new DynoEstimator)->estimate($maps, $spec, $original, $this->apply($base, $proposals, $maps), $overrides);
        if ($options->maxTorque !== null && ($dyno['modified']['peakTorque']['value'] ?? 0) > $options->maxTorque) {
            $this->warnings[] = sprintf('Estimated peak torque %d Nm exceeds the %d Nm clutch/gearbox limit you entered. Lower the fuel increase or cap the torque limiter.',
                $dyno['modified']['peakTorque']['value'], $options->maxTorque);
        }

        return [
            ...$result,
            'available' => true,
            'proposals' => $proposals,
            'warnings' => array_values(array_unique($this->warnings)),
            'dyno' => $dyno,
        ];
    }

    /**
     * Writes proposals into a buffer.
     *
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  MapDef[]  $maps  definitions the proposals refer to by id
     */
    public function apply(Bin $bin, array $proposals, array $maps): Bin
    {
        $byId = [];
        foreach ($maps as $map) {
            $byId[$map->id] = $map;
        }
        foreach ($proposals as $proposal) {
            $bin = MapWriter::write($bin, $byId[$proposal['mapId']], $proposal['values']);
        }

        return $bin;
    }

    /**
     * @param  array<string, MapValues[]>  $v  maps per role
     * @return array<int, array<string, mixed>>
     */
    private function diesel(array $v, Stage1Options $o): array
    {
        $fuel = $o->fuelIncrease / 100;
        $boost = $o->boostIncrease / 100;
        $capFuel = fn (float $old, float $new): float => $o->maxFuel === null ? $new : $this->softCap($old, $new, $o->maxFuel);

        if (! $v['torqueLimit'] && ! $v['fullLoadLimit'] && ! $v['wish']) {
            return [];
        }

        $out = [
            ...$this->proposals($v, 'torqueLimit',
                fn (float $old): float => $capFuel($old, $old * (1 + $fuel)),
                sprintf('Raise the fuel quantity limit by %d %% across the rev range.', $o->fuelIncrease)),
            ...$this->proposals($v, 'fullLoadLimit',
                fn (float $old): float => $capFuel($old, $old * (1 + $fuel)),
                sprintf('Raise the full-load fuel limit by %d %% so it does not cap the new torque limit.', $o->fuelIncrease)),
            ...$this->proposals($v, 'wish',
                fn (float $old, float $rpm, float $pedal, MapValues $map): float => $capFuel($old, $old * (1 + $fuel * $this->weight($map, $pedal, 0.6, 1.0))),
                sprintf('Raise the requested fuel from 60 %% pedal upwards, reaching +%d %% at full pedal, so the request does not cap the new limit.', $o->fuelIncrease)),
        ];

        if ($v['smoke']) {
            $out = [...$out, ...$this->proposals($v, 'smoke',
                fn (float $old, float $rpm, float $air, MapValues $map): float => $capFuel($old, $old * (1 + $fuel * $this->weight($map, $air, 0.3, 0.8))),
                sprintf('Raise the smoke limit by up to %d %% at high air mass only; low air mass (spool-up) keeps the stock limit to avoid smoke.', $o->fuelIncrease))];
        } else {
            $this->warnings[] = 'No smoke limiter found. More fuel without a matching smoke limit can cause heavy smoke and high exhaust gas temperature.';
        }

        if ($v['boost']) {
            $capped = 0;
            $out = [...$out, ...$this->proposals($v, 'boost',
                function (float $old, float $rpm, float $quantity, MapValues $map) use ($boost, $o, &$capped): float {
                    $cap = $this->fromMbar($map->map->unit, $o->maxBoost);
                    $rpmWeight = $this->ramp($rpm, 1200, 1800) * (1 - 0.5 * $this->ramp($rpm, 3000, 4500));
                    $new = $old * (1 + $boost * $this->weight($map, $quantity, 0.4, 0.8) * $rpmWeight);
                    if ($new > $cap && $new > $old) {
                        $capped++;
                    }

                    return $this->softCap($old, $new, $cap);
                },
                sprintf('Raise boost by up to %d %% at high fuel quantity, faded out below 1500 rpm and halved towards 4500 rpm to protect the turbo; capped at %d mbar.', $o->boostIncrease, $o->maxBoost))];
            if ($capped) {
                $this->warnings[] = sprintf('The boost target hit the %d mbar cap in %d cells.', $o->maxBoost, $capped);
            }
        } else {
            $this->warnings[] = 'No boost pressure target found. Extra fuel without extra air raises smoke and exhaust temperature.';
        }

        if ($v['boost']) {
            $out = [...$out, ...$this->proposals($v, 'boostLimit',
                fn (float $old, float $rpm, float $other, MapValues $map): float => $this->softCap($old, $old * (1 + $boost), $this->fromMbar($map->map->unit, $o->maxBoost + self::BOOST_LIMIT_MARGIN)),
                sprintf('Raise the boost limiter by %d %% (at most %d mbar) so it does not cut the new boost target.', $o->boostIncrease, $o->maxBoost + self::BOOST_LIMIT_MARGIN))];
        }

        return $out;
    }

    /**
     * @param  array<string, MapValues[]>  $v  maps per role
     * @return array<int, array<string, mixed>>
     */
    private function petrol(array $v, Stage1Options $o): array
    {
        if (! $v['ldrxn']) {
            return [];
        }
        $load = $o->loadIncrease / 100;
        $cap = fn (float $old, float $new): float => $this->softCap($old, $new, $o->maxLoad);

        $out = $this->proposals($v, 'ldrxn',
            fn (float $old): float => $cap($old, $old * (1 + $load)),
            sprintf('Raise the maximum load by %d %%, capped at %d %%.', $o->loadIncrease, $o->maxLoad));

        if ($v['kfmirl']) {
            $out = [...$out, ...$this->proposals($v, 'kfmirl',
                fn (float $old, float $rpm, float $request, MapValues $map): float => $cap($old, $old * (1 + $load * $this->weight($map, $request, 0.6, 1.0))),
                sprintf('Raise the load requested at high torque request by up to %d %% so the driver can reach the new LDRXN.', $o->loadIncrease))];
        } else {
            $this->warnings[] = 'KFMIRL was not found. The torque model may stop the engine from requesting the higher load.';
        }

        $this->warnings[] = 'Ignition (KFZW) is not changed. More load means more knock risk: datalog timing pulls before going further.';

        return $out;
    }

    /**
     * Naturally aspirated engines cannot take more air, so full-load power barely moves. The
     * useful change is response: more torque request in the first part of the pedal travel, with
     * full pedal unchanged.
     *
     * @param  array<string, MapValues[]>  $v  maps per role
     * @return array<int, array<string, mixed>>
     */
    private function naturallyAspirated(array $v, Stage1Options $o, EngineSpec $spec): array
    {
        $role = $spec->diesel ? 'wish' : 'pedal';
        $summary = sprintf('Sharpen throttle response: up to +%d %% torque request around 30 %% pedal, fading out towards idle and full pedal. Full pedal stays the same, the map stays rising with pedal.', $o->responseIncrease);

        return array_map(fn (MapValues $values): array => $this->responseProposal($role, $values, $o->responseIncrease / 100, $summary), $v[$role] ?? []);
    }

    /**
     * Raises each engine speed line of a pedal map with a bell over the pedal travel (peak at
     * 30 % pedal), keeps it rising with pedal and never above the full-pedal value of that line.
     *
     * @return array<string, mixed>
     */
    private function responseProposal(string $role, MapValues $values, float $increase, string $summary): array
    {
        $map = $values->map;
        $pedalOnRows = $values->rpmOnX();
        $pedal = $pedalOnRows ? $values->y : $values->x;
        $lines = $pedalOnRows ? $map->cols : $map->rows;
        $min = min($pedal);
        $span = max(max($pedal) - $min, 1e-9);
        $grid = $values->grid;

        for ($line = 0; $line < $lines; $line++) {
            $cell = fn (int $i): array => $pedalOnRows ? [$i, $line] : [$line, $i];
            [$fr, $fc] = $cell(count($pedal) - 1);
            $full = $values->grid[$fr][$fc];
            $previous = -INF;
            foreach (array_keys($pedal) as $i) {
                [$r, $c] = $cell($i);
                $old = $values->grid[$r][$c];
                $f = ($pedal[$i] - $min) / $span;
                $bell = $this->ramp($f, 0.0, 0.3) * (1 - $this->ramp($f, 0.3, 1.0));
                $new = max($old, min($old * (1 + $increase * $bell), $full), $previous);
                $grid[$r][$c] = $this->quantize($map, $new);
                $previous = $grid[$r][$c];
            }
        }

        return $this->package($role, $values, $grid, $summary);
    }

    /**
     * One proposal per map of a role, all built with the same rule.
     *
     * @param  array<string, MapValues[]>  $v
     * @param  callable(float, float, float, MapValues): float  $fn
     * @return array<int, array<string, mixed>>
     */
    private function proposals(array $v, string $role, callable $fn, string $summary): array
    {
        return array_map(fn (MapValues $values): array => $this->proposal($role, $values, $fn, $summary), $v[$role] ?? []);
    }

    /**
     * Builds one proposal by mapping every cell through $fn(old, rpm, other axis value, map).
     *
     * @param  callable(float, float, float, MapValues): float  $fn
     * @return array<string, mixed>
     */
    private function proposal(string $role, MapValues $values, callable $fn, string $summary): array
    {
        $grid = [];
        foreach ($values->grid as $r => $row) {
            foreach ($row as $c => $old) {
                [$rpm, $other] = $this->cellAxes($values, $r, $c);
                $grid[$r][$c] = $this->quantize($values->map, $fn($old, $rpm, $other, $values));
            }
        }

        return $this->package($role, $values, $grid, $summary);
    }

    /**
     * @param  float[][]  $grid  new physical values
     * @return array<string, mixed>
     */
    private function package(string $role, MapValues $values, array $grid, string $summary): array
    {
        $map = $values->map;
        $changed = 0;
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $new) {
                if (abs($new - $values->grid[$r][$c]) > 1e-9) {
                    $changed++;
                }
            }
        }
        $before = array_merge(...$values->grid);
        $after = array_merge(...$grid);

        return [
            'mapId' => $map->id,
            'name' => $map->name,
            'role' => $role,
            'summary' => $summary,
            'unit' => $map->unit,
            'changedCells' => $changed,
            'before' => ['min' => min($before), 'max' => max($before)],
            'after' => ['min' => min($after), 'max' => max($after)],
            'values' => $grid,
        ];
    }

    /**
     * @return array{0: float, 1: float} engine speed and other axis value of a cell
     */
    private function cellAxes(MapValues $values, int $r, int $c): array
    {
        $map = $values->map;
        if ($map->rows === 1) {
            return [$values->x[$c], 0.0];
        }
        if ($map->cols === 1) {
            return [$values->y[$r], 0.0];
        }

        return $values->rpmOnX() ? [$values->x[$c], $values->y[$r]] : [$values->y[$r], $values->x[$c]];
    }

    /** Rounds a physical value to what the storage type can hold, so the proposal shows what gets written. */
    private function quantize(MapDef $map, float $value): float
    {
        $raw = MapWriter::storable(($value - $map->offset) / ($map->factor ?: 1), $map->dtype);

        return round($raw * $map->factor + $map->offset, 6);
    }

    /** Position of $value along the map's non-rpm axis, faded in between two fractions of its span. */
    private function weight(MapValues $values, float $value, float $from, float $to): float
    {
        $axis = $values->otherAxis();
        $min = min($axis);
        $span = max($axis) - $min;

        return $span > 0 ? $this->ramp(($value - $min) / $span, $from, $to) : 1.0;
    }

    /** Smoothstep fade from 0 at $from to 1 at $to: no kink where the change starts or ends. */
    private function ramp(float $value, float $from, float $to): float
    {
        $t = max(0.0, min(1.0, ($value - $from) / ($to - $from)));

        return $t * $t * (3 - 2 * $t);
    }

    /**
     * Limits a raised value to $cap with a soft knee over the last 3 % below it, so cells near the
     * cap bend into it instead of forming a flat plateau with a sharp edge. Never lowers $old.
     */
    private function softCap(float $old, float $new, float $cap): float
    {
        $band = max(abs($cap) * 0.03, 1e-6);
        $knee = $cap - $band;
        if ($new <= $old || $new <= $knee) {
            return $new;
        }

        return max($old, $knee + $band * (1 - exp(-($new - $knee) / $band)));
    }

    private function fromMbar(string $unit, float $mbar): float
    {
        return match (strtolower($unit)) {
            'bar' => $mbar / 1000,
            'kpa' => $mbar / 10,
            default => $mbar,
        };
    }

    /**
     * Warns when the new full-load fuel quantity runs past the fuel axis of the injection duration maps.
     * The effective limit is the lowest of the fuel limits, each at its highest value.
     *
     * @param  MapDef[]  $maps
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function checkInjectionDuration(array $maps, Bin $bin, array $proposals): void
    {
        $peaks = [];
        foreach ($proposals as $p) {
            if (in_array($p['role'], ['torqueLimit', 'fullLoadLimit', 'wish'], true)) {
                $peaks[$p['role']] = max($peaks[$p['role']] ?? 0.0, $p['after']['max']);
            }
        }
        if (! $peaks) {
            return;
        }
        $warning = InjectionCheck::warning($maps, $bin, min($peaks));
        if ($warning !== null) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * @return string[]
     */
    private function notes(EngineSpec $spec, bool $turbo): array
    {
        return array_values(array_filter([
            $turbo ? null : 'Naturally aspirated engine: software alone adds little full-load power (a few percent at best, usually from ignition and lambda on a dyno). This proposal only changes how the throttle responds.',
            'Emission maps (EGR, DPF, SCR, lambda) are left unchanged.',
            match (true) {
                ! $turbo => 'Test drive before and after: the pedal should feel livelier in the first third without jerking on and off throttle.',
                $spec->diesel => 'Datalog before and after: boost actual vs. target, rail pressure, exhaust gas temperature and smoke.',
                default => 'Datalog before and after: boost actual vs. target, ignition timing pulls, lambda and intake temperature.',
            },
            'Correct the checksums before flashing.',
        ]));
    }
}
