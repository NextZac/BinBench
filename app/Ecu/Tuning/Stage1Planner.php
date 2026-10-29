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
    /** Headroom kept between the boost target and the boost limiter, mbar. */
    private const BOOST_LIMIT_MARGIN = 100.0;

    /** @var string[] */
    private array $warnings = [];

    /**
     * @param  MapDef[]  $maps
     * @param  Bin  $original  the unmodified file, used as the dyno baseline
     * @param  Bin  $base  the buffer the proposal builds on (the editor's current state)
     * @return array<string, mixed>
     */
    public function plan(array $maps, EngineSpec $spec, Bin $original, Bin $base, Stage1Options $options): array
    {
        $this->warnings = [];
        $roles = MapRoles::assign($maps, $base, $spec->diesel ? MapRoles::DIESEL : MapRoles::PETROL);
        $values = array_map(fn (MapDef $m): MapValues => new MapValues($m, $base), $roles);

        $result = [
            'available' => false,
            'reason' => null,
            'options' => $options->toArray(),
            'proposals' => [],
            'warnings' => [],
            'notes' => $this->notes($spec),
            'dyno' => null,
        ];

        $proposals = $spec->diesel ? $this->diesel($values, $options) : $this->petrol($values, $options);
        $proposals = array_values(array_filter($proposals, fn (array $p): bool => $p['changedCells'] > 0));
        if (! $proposals) {
            $result['reason'] = $spec->diesel
                ? "No torque limiter or driver's wish map was found, so there is nothing to base a Stage 1 on."
                : 'LDRXN was not found, so there is nothing to base a Stage 1 on.';

            return $result;
        }

        if ($spec->diesel) {
            $this->checkInjectionDuration($maps, $base, $proposals);
        }

        $dyno = (new DynoEstimator)->estimate($maps, $spec, $original, $this->apply($base, $proposals, $roles));
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
     * @param  array<string, MapDef>  $roles
     */
    public function apply(Bin $bin, array $proposals, array $roles): Bin
    {
        foreach ($proposals as $proposal) {
            $bin = MapWriter::write($bin, $roles[$proposal['role']], $proposal['values']);
        }

        return $bin;
    }

    /**
     * @param  array<string, MapValues>  $v
     * @return array<int, array<string, mixed>>
     */
    private function diesel(array $v, Stage1Options $o): array
    {
        $fuel = $o->fuelIncrease / 100;
        $boost = $o->boostIncrease / 100;
        $capFuel = fn (float $old, float $new): float => $o->maxFuel === null ? $new : max($old, min($new, $o->maxFuel));
        $out = [];

        if (! isset($v['torqueLimit']) && ! isset($v['wish'])) {
            return [];
        }

        if (isset($v['torqueLimit'])) {
            $out[] = $this->proposal('torqueLimit', $v['torqueLimit'],
                fn (float $old): float => $capFuel($old, $old * (1 + $fuel)),
                sprintf('Raise the fuel quantity limit by %d %% across the rev range.', $o->fuelIncrease));
        }

        if (isset($v['wish'])) {
            $out[] = $this->proposal('wish', $v['wish'],
                fn (float $old, float $rpm, float $pedal): float => $capFuel($old, $old * (1 + $fuel * $this->weight($v['wish'], $pedal, 0.6, 1.0))),
                sprintf('Raise the requested fuel from 60 %% pedal upwards, reaching +%d %% at full pedal, so the request does not cap the new limit.', $o->fuelIncrease));
        }

        if (isset($v['smoke'])) {
            $out[] = $this->proposal('smoke', $v['smoke'],
                fn (float $old, float $rpm, float $air): float => $capFuel($old, $old * (1 + $fuel * $this->weight($v['smoke'], $air, 0.3, 0.8))),
                sprintf('Raise the smoke limit by up to %d %% at high air mass only; low air mass (spool-up) keeps the stock limit to avoid smoke.', $o->fuelIncrease));
        } else {
            $this->warnings[] = 'No smoke limiter found. More fuel without a matching smoke limit can cause heavy smoke and high exhaust gas temperature.';
        }

        if (isset($v['boost'])) {
            $cap = $this->fromMbar($v['boost']->map->unit, $o->maxBoost);
            $capped = 0;
            $out[] = $this->proposal('boost', $v['boost'],
                function (float $old, float $rpm, float $quantity) use ($v, $boost, $cap, &$capped): float {
                    $rpmWeight = $this->ramp($rpm, 1200, 1800) * (1 - 0.5 * $this->ramp($rpm, 3000, 4500));
                    $new = $old * (1 + $boost * $this->weight($v['boost'], $quantity, 0.4, 0.8) * $rpmWeight);
                    if ($new > $cap && $new > $old) {
                        $capped++;

                        return max($old, $cap);
                    }

                    return $new;
                },
                sprintf('Raise boost by up to %d %% at high fuel quantity, faded out below 1500 rpm and halved towards 4500 rpm to protect the turbo; capped at %d mbar.', $o->boostIncrease, $o->maxBoost));
            if ($capped) {
                $this->warnings[] = sprintf('The boost target hit the %d mbar cap in %d cells.', $o->maxBoost, $capped);
            }
        } else {
            $this->warnings[] = 'No boost pressure target found. Extra fuel without extra air raises smoke and exhaust temperature.';
        }

        if (isset($v['boostLimit'], $v['boost'])) {
            $cap = $this->fromMbar($v['boostLimit']->map->unit, $o->maxBoost + self::BOOST_LIMIT_MARGIN);
            $out[] = $this->proposal('boostLimit', $v['boostLimit'],
                fn (float $old): float => max($old, min($old * (1 + $boost), $cap)),
                sprintf('Raise the boost limiter by %d %% (at most %d mbar) so it does not cut the new boost target.', $o->boostIncrease, $o->maxBoost + self::BOOST_LIMIT_MARGIN));
        }

        return $out;
    }

    /**
     * @param  array<string, MapValues>  $v
     * @return array<int, array<string, mixed>>
     */
    private function petrol(array $v, Stage1Options $o): array
    {
        if (! isset($v['ldrxn'])) {
            return [];
        }
        $load = $o->loadIncrease / 100;
        $cap = fn (float $old, float $new): float => max($old, min($new, $o->maxLoad));

        $out = [$this->proposal('ldrxn', $v['ldrxn'],
            fn (float $old): float => $cap($old, $old * (1 + $load)),
            sprintf('Raise the maximum load by %d %%, capped at %d %%.', $o->loadIncrease, $o->maxLoad))];

        if (isset($v['kfmirl'])) {
            $out[] = $this->proposal('kfmirl', $v['kfmirl'],
                fn (float $old, float $rpm, float $request): float => $cap($old, $old * (1 + $load * $this->weight($v['kfmirl'], $request, 0.6, 1.0))),
                sprintf('Raise the load requested at high torque request by up to %d %% so the driver can reach the new LDRXN.', $o->loadIncrease));
        } else {
            $this->warnings[] = 'KFMIRL was not found. The torque model may stop the engine from requesting the higher load.';
        }

        $this->warnings[] = 'Ignition (KFZW) is not changed. More load means more knock risk: datalog timing pulls before going further.';

        return $out;
    }

    /**
     * Builds one proposal by mapping every cell through $fn(old, rpm, other axis value).
     *
     * @param  callable(float, float, float): float  $fn
     * @return array<string, mixed>
     */
    private function proposal(string $role, MapValues $values, callable $fn, string $summary): array
    {
        $map = $values->map;
        $grid = [];
        $changed = 0;
        foreach ($values->grid as $r => $row) {
            foreach ($row as $c => $old) {
                [$rpm, $other] = $this->cellAxes($values, $r, $c);
                $new = $this->quantize($map, $fn($old, $rpm, $other));
                $grid[$r][$c] = $new;
                if (abs($new - $old) > 1e-9) {
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

    private function ramp(float $value, float $from, float $to): float
    {
        return max(0.0, min(1.0, ($value - $from) / ($to - $from)));
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
     * Warns when the new fuel quantity runs past the fuel axis of the injection duration maps.
     *
     * @param  MapDef[]  $maps
     * @param  array<int, array<string, mixed>>  $proposals
     */
    private function checkInjectionDuration(array $maps, Bin $bin, array $proposals): void
    {
        $peak = max(array_map(fn (array $p): float => $p['after']['max'], array_filter($proposals, fn (array $p): bool => $p['role'] === 'torqueLimit' || $p['role'] === 'wish')) ?: [0.0]);
        foreach (MapRoles::all($maps, $bin, 'duration') as $map) {
            $values = new MapValues($map, $bin);
            $axis = preg_match('/mg/i', (string) $map->x?->unit) ? $values->x : (preg_match('/mg/i', (string) $map->y?->unit) ? $values->y : []);
            if ($axis && max($axis) < $peak) {
                $this->warnings[] = sprintf('%s ends at %.1f mg/st but the new limit reaches %.1f mg/st. Extend the duration maps (and check the injectors) before using this.', $map->name, max($axis), $peak);
            }
        }
    }

    /**
     * @return string[]
     */
    private function notes(EngineSpec $spec): array
    {
        return [
            'Emission maps (EGR, DPF, SCR, lambda) are left unchanged.',
            $spec->diesel
                ? 'Datalog before and after: boost actual vs. target, rail pressure, exhaust gas temperature and smoke.'
                : 'Datalog before and after: boost actual vs. target, ignition timing pulls, lambda and intake temperature.',
            'Correct the checksums before flashing.',
        ];
    }
}
