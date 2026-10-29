<?php

namespace App\Ecu\Dyno;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapRoles;
use App\Ecu\Maps\MapValues;

/**
 * Estimates full-load torque and power curves from the limiting maps of a file.
 *
 * Diesel: fuel quantity = min(driver's wish at full pedal, torque limiter, smoke limiter at the
 * air mass the boost target delivers); torque follows from the fuel energy per cycle.
 * Petrol (ME7): load = min(LDRXN, KFMIRL at full torque request); torque follows from the air,
 * burnt at stoichiometric.
 * Ignition, injection timing, lambda and turbo limits are not modelled, so absolute values are
 * estimates. Entering the stock power scales both curves to it.
 */
class DynoEstimator
{
    private const DIESEL_HEATING_VALUE = 42.6e6;

    /** mg per mm³ */
    private const DIESEL_DENSITY = 0.835;

    private const PETROL_HEATING_VALUE = 43.4e6;

    private const STOICH_AFR = 14.7;

    private const AIR_GAS_CONSTANT = 287.0;

    /** Charge air temperature after the intercooler, K. */
    private const CHARGE_TEMP = 323.0;

    private const VOLUMETRIC_EFFICIENCY = 0.9;

    /** Air density at the conditions ME7 relates relative load (rl = 100 %) to, kg/m³. */
    private const REFERENCE_AIR_DENSITY = 1.2;

    private const STANDARD_ATM = 1013.0;

    private const RPM_STEP = 100;

    private const DIESEL_ROLES = ['wish', 'torqueLimit', 'fullLoadLimit', 'smoke', 'boost', 'boostLimit'];

    private const LIMITER_LABELS = [
        'wish' => "Driver's wish",
        'torqueLimit' => 'Torque limiter',
        'fullLoadLimit' => 'Full-load limit',
        'smoke' => 'Smoke limiter',
        'ldrxn' => 'Max load (LDRXN)',
        'kfmirl' => 'Torque request (KFMIRL)',
    ];

    /** @var string[] */
    private array $notes = [];

    /**
     * @param  MapDef[]  $maps  definitions of the file
     * @param  array<string, string>  $overrides  role => map id chosen by the user, or MapRoles::NONE
     * @return array<string, mixed>
     */
    public function estimate(array $maps, EngineSpec $spec, Bin $stock, ?Bin $modified = null, array $overrides = []): array
    {
        $this->notes = [];
        $wanted = $spec->diesel ? self::DIESEL_ROLES : MapRoles::PETROL;
        $roles = MapRoles::assign($maps, $stock, $wanted, $overrides);
        $required = $spec->diesel ? ['wish', 'torqueLimit', 'fullLoadLimit'] : ['ldrxn', 'kfmirl'];

        $result = [
            'available' => false,
            'reason' => null,
            'engine' => $spec->toArray(),
            'calibrated' => false,
            'roles' => MapRoles::describe($maps, $stock, $wanted, $overrides),
            'notes' => [],
            'stock' => null,
            'modified' => null,
        ];

        if (! array_intersect_key($roles, array_flip($required))) {
            $result['reason'] = $spec->diesel
                ? "No driver's wish or torque limiter map was found. Import an XDF or name the maps to enable the dyno."
                : 'Neither LDRXN nor KFMIRL was found. Import an XDF or name the maps to enable the dyno.';

            return $result;
        }

        $stockCurve = $this->curve($roles, $spec, $stock);
        $modifiedCurve = $modified ? $this->curve($roles, $spec, $modified) : null;

        if ($spec->stockPower !== null && $stockCurve['peakPower']['value'] > 0) {
            $scale = $spec->stockPower / $stockCurve['peakPower']['value'];
            if ($scale < 0.6 || $scale > 1.6) {
                $this->notes[] = sprintf('Calibration scaled the estimate by %.2f. That is a large correction: check the engine data and the detected maps.', $scale);
            }
            $stockCurve = $this->scaled($stockCurve, $scale);
            $modifiedCurve = $modifiedCurve ? $this->scaled($modifiedCurve, $scale) : null;
            $result['calibrated'] = true;
        }

        if ($spec->assumed) {
            $this->notes[] = sprintf('Engine data was not found in the file; assuming %.1f l, %d cylinders.', $spec->displacement, $spec->cylinders);
        }

        return [
            ...$result,
            'available' => true,
            'notes' => array_values(array_unique($this->notes)),
            'stock' => $stockCurve,
            'modified' => $modifiedCurve,
        ];
    }

    /**
     * @param  array<string, MapDef>  $roles
     * @return array<string, mixed>
     */
    private function curve(array $roles, EngineSpec $spec, Bin $bin): array
    {
        $values = array_map(fn (MapDef $m): MapValues => new MapValues($m, $bin), $roles);
        [$from, $to] = $this->rpmRange($values, $spec->diesel);

        $curve = ['rpm' => [], 'torque' => [], 'power' => [], 'powerKw' => [], 'fuel' => [], 'limiter' => []];
        for ($rpm = $from; $rpm <= $to; $rpm += self::RPM_STEP) {
            [$torque, $fuel, $limiter] = $spec->diesel
                ? $this->dieselPoint($values, $spec, $rpm)
                : $this->petrolPoint($values, $spec, $rpm);
            $torque = max(0.0, $torque);
            $curve['rpm'][] = $rpm;
            $curve['torque'][] = round($torque, 1);
            $curve['power'][] = round($torque * $rpm / 7023.5, 1);
            $curve['powerKw'][] = round($torque * $rpm / 9549.3, 1);
            $curve['fuel'][] = round($fuel, 2);
            $curve['limiter'][] = $limiter;
        }

        return $this->withPeaks($curve);
    }

    /**
     * @param  array<string, MapValues>  $values
     * @return array{0: float, 1: float, 2: string} torque Nm, fuel quantity mg/st, active limiter
     */
    private function dieselPoint(array $values, EngineSpec $spec, int $rpm): array
    {
        $limits = [];
        if (isset($values['wish'])) {
            $pedal = $values['wish']->otherAxis();
            $limits['wish'] = $this->toMg($values['wish'], $values['wish']->atRpm($rpm, $pedal ? max($pedal) : 0.0));
        }
        foreach (['torqueLimit', 'fullLoadLimit'] as $role) {
            if (isset($values[$role])) {
                $limits[$role] = $this->toMg($values[$role], $values[$role]->atRpm($rpm, $this->pressureIn($values[$role], self::STANDARD_ATM)));
            }
        }

        $quantity = min($limits);
        if (isset($values['smoke'])) {
            if (isset($values['boost'])) {
                $boost = $this->toMbar($values['boost']->map->unit, $values['boost']->atRpm($rpm, $quantity));
                if (isset($values['boostLimit'])) {
                    $ceiling = $values['boostLimit']->atRpm($rpm, $this->pressureIn($values['boostLimit'], self::STANDARD_ATM));
                    $boost = min($boost, $this->toMbar($values['boostLimit']->map->unit, $ceiling));
                }
                $airMass = $boost * 100 * $spec->cylinderVolume() * self::VOLUMETRIC_EFFICIENCY
                    / (self::AIR_GAS_CONSTANT * self::CHARGE_TEMP) * 1e6;
                $limits['smoke'] = $this->toMg($values['smoke'], $values['smoke']->atRpm($rpm, $airMass));
            } else {
                $this->notes[] = 'Smoke limiter found but no boost pressure target, so the smoke limit is not applied.';
            }
        } else {
            $this->notes[] = 'No smoke limiter found; the estimate may be too high where air is the limit.';
        }

        $active = array_keys($limits, min($limits))[0];
        $quantity = $limits[$active];
        $energy = $quantity * 1e-6 * $spec->cylinders * self::DIESEL_HEATING_VALUE * $this->dieselEfficiency($rpm);

        return [$energy / (4 * M_PI), $quantity, self::LIMITER_LABELS[$active]];
    }

    /**
     * @param  array<string, MapValues>  $values
     * @return array{0: float, 1: float, 2: string} torque Nm, relative load %, active limiter
     */
    private function petrolPoint(array $values, EngineSpec $spec, int $rpm): array
    {
        $limits = [];
        if (isset($values['ldrxn'])) {
            $limits['ldrxn'] = $values['ldrxn']->atRpm($rpm, 0.0);
        }
        if (isset($values['kfmirl'])) {
            $request = $values['kfmirl']->otherAxis();
            $limits['kfmirl'] = $values['kfmirl']->atRpm($rpm, $request ? max($request) : 0.0);
        }

        $active = array_keys($limits, min($limits))[0];
        $load = $limits[$active];
        $airMass = $load / 100 * $spec->cylinderVolume() * self::REFERENCE_AIR_DENSITY;
        $energy = $airMass / self::STOICH_AFR * $spec->cylinders * self::PETROL_HEATING_VALUE * $this->petrolEfficiency($rpm);

        return [$energy / (4 * M_PI), $load, self::LIMITER_LABELS[$active]];
    }

    /** Fuel quantity in mg/stroke; maps in mm³/stroke are converted with the density of diesel. */
    private function toMg(MapValues $values, float $quantity): float
    {
        return preg_match('/mm\^?3|mm³/i', $values->map->unit) ? $quantity * self::DIESEL_DENSITY : $quantity;
    }

    /** Brake efficiency of a common-rail / PD diesel, falling off with friction at high speed. */
    private function dieselEfficiency(int $rpm): float
    {
        return max(0.28, 0.40 - 0.025 * (($rpm - 2000) / 1000) ** 2);
    }

    private function petrolEfficiency(int $rpm): float
    {
        return max(0.22, 0.27 - 0.02 * (($rpm - 3500) / 1500) ** 2);
    }

    /** A pressure in mbar expressed in the unit of the map's non-rpm axis. */
    private function pressureIn(MapValues $values, float $mbar): float
    {
        return match (strtolower($values->otherAxisDef()?->unit ?? '')) {
            'bar' => $mbar / 1000,
            'kpa' => $mbar / 10,
            default => $mbar,
        };
    }

    private function toMbar(string $unit, float $value): float
    {
        return match (strtolower($unit)) {
            'bar' => $value * 1000,
            'kpa' => $value * 10,
            default => $value,
        };
    }

    /**
     * Engine speed range covered by the maps in use.
     *
     * @param  array<string, MapValues>  $values
     * @return array{0: int, 1: int}
     */
    private function rpmRange(array $values, bool $diesel): array
    {
        $max = 0.0;
        foreach ($values as $role => $v) {
            if (in_array($role, ['wish', 'torqueLimit', 'fullLoadLimit', 'ldrxn', 'kfmirl'], true)) {
                $axis = $v->rpmAxis();
                $max = max($max, end($axis) ?: 0.0);
            }
        }
        [$from, $cap] = $diesel ? [1000, 5500] : [1500, 7500];
        $to = (int) (floor(min($max ?: $cap, $cap) / self::RPM_STEP) * self::RPM_STEP);

        return [$from, max($from + self::RPM_STEP, $to)];
    }

    /**
     * @param  array<string, mixed>  $curve
     * @return array<string, mixed>
     */
    private function withPeaks(array $curve): array
    {
        foreach (['torque' => 'peakTorque', 'power' => 'peakPower'] as $series => $key) {
            $peak = max($curve[$series]);
            $curve[$key] = ['value' => $peak, 'rpm' => $curve['rpm'][array_search($peak, $curve[$series], true)]];
        }

        return $curve;
    }

    /**
     * @param  array<string, mixed>  $curve
     * @return array<string, mixed>
     */
    private function scaled(array $curve, float $scale): array
    {
        foreach (['torque', 'power', 'powerKw'] as $series) {
            $curve[$series] = array_map(fn (float $v): float => round($v * $scale, 1), $curve[$series]);
        }

        return $this->withPeaks($curve);
    }
}
