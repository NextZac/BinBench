<?php

namespace App\Ecu\Tuning;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapRoles;
use App\Ecu\Maps\MapValues;

/**
 * Checks that the injection duration maps cover the highest fuel quantity the limits allow.
 * Past the end of their fuel axis the ECU holds the last energizing time, so extra fuel is
 * either not injected or, with extended maps elsewhere, mismatched.
 */
class InjectionCheck
{
    /**
     * Warning text when duration maps end below $peak, else null.
     *
     * @param  MapDef[]  $maps
     */
    public static function warning(array $maps, Bin $bin, float $peak): ?string
    {
        $short = [];
        $fuelAxis = '/mg|mm\^?3|mm³/i';
        foreach (MapRoles::candidates($maps, $bin, 'duration') as $map) {
            $values = new MapValues($map, $bin);
            $axis = preg_match($fuelAxis, (string) $map->x?->unit) ? $values->x : (preg_match($fuelAxis, (string) $map->y?->unit) ? $values->y : []);
            if ($axis && max($axis) < $peak) {
                $short[] = [$map->name, max($axis)];
            }
        }
        if (! $short) {
            return null;
        }

        return sprintf('%s end%s at %.1f on the fuel axis, but the new fuel limit reaches %.1f. Extend the duration maps (and check the injectors) before using this.',
            count($short) === 1 ? $short[0][0] : count($short).' injection duration maps ('.$short[0][0].', ...)',
            count($short) === 1 ? 's' : '', min(array_column($short, 1)), $peak);
    }
}
