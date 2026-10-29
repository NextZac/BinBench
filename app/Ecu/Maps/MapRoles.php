<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Finds the maps that play a known part in the torque path (limiters, boost, load) by name,
 * covering classifier names, the ME7 catalog and common XDF titles.
 */
class MapRoles
{
    public const DIESEL = ['wish', 'torqueLimit', 'smoke', 'boost', 'boostLimit', 'duration'];

    public const PETROL = ['ldrxn', 'kfmirl'];

    /** @var array<string, string> */
    private const PATTERNS = [
        'wish' => "/driver.?s wish|fahrerwunsch|\bmrfa\b/i",
        'torqueLimit' => '/torque limit|drehmomentbegrenz|mengenbegrenz/i',
        'smoke' => '/smoke|rauch/i',
        'boost' => '/boost pressure target|ladedruck.?soll|\bldrsoll/i',
        'boostLimit' => '/boost pressure limit|ladedruck.?begrenz/i',
        'duration' => '/injection duration|ansteuerdauer|energizing/i',
        'ldrxn' => '/^LDRXN$/i',
        'kfmirl' => '/^KFMIRL$/i',
    ];

    /**
     * Picks the most confident usable map for each role.
     *
     * @param  MapDef[]  $maps
     * @param  string[]  $roles
     * @return array<string, MapDef>
     */
    public static function assign(array $maps, Bin $bin, array $roles): array
    {
        usort($maps, fn (MapDef $a, MapDef $b): int => [$b->confidence, $a->address] <=> [$a->confidence, $b->address]);

        $found = [];
        foreach ($roles as $role) {
            foreach ($maps as $map) {
                if (preg_match(self::PATTERNS[$role], $map->name) && self::isUsable($map, $bin)) {
                    $found[$role] = $map;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Every usable map of one role, e.g. the whole set of injection duration maps.
     *
     * @param  MapDef[]  $maps
     * @return MapDef[]
     */
    public static function all(array $maps, Bin $bin, string $role): array
    {
        return array_values(array_filter($maps, fn (MapDef $m): bool => preg_match(self::PATTERNS[$role], $m->name) === 1 && self::isUsable($m, $bin)));
    }

    private static function isUsable(MapDef $map, Bin $bin): bool
    {
        try {
            $values = new MapValues($map, $bin);
        } catch (\RangeException) {
            return false;
        }

        return ($map->cols < 2 || MapValues::isAscending($values->x))
            && ($map->rows < 2 || MapValues::isAscending($values->y));
    }
}
