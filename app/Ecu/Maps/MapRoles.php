<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Finds the maps that play a known part in the torque path (limiters, boost, load).
 *
 * A map qualifies for a role when its name matches, it is not a special-purpose map of the same
 * kind (launch control, multimap copies, monitoring, corrections...), its unit fits the role, it
 * has an engine speed axis and its other axis measures the right thing (pedal, air mass, fuel
 * quantity, pressure). Files often hold a set of maps per role (driver's wish per speed range,
 * torque limiter per gear group); the best one drives the dyno, Stage 1 changes all of them.
 * The user can override any role with a specific map or switch it off.
 */
class MapRoles
{
    public const DIESEL = ['wish', 'torqueLimit', 'fullLoadLimit', 'smoke', 'boost', 'boostLimit', 'duration'];

    public const PETROL = ['ldrxn', 'kfmirl', 'pedal'];

    /** Override value that switches a role off. */
    public const NONE = 'none';

    /**
     * Name pattern, value unit and what the non-rpm axis measures ('none' for curves over rpm).
     *
     * @var array<string, array{name: string, unit: ?string, other: string[]}>
     */
    private const SPEC = [
        'wish' => ['name' => "/driver.?s wish|fahrerwunsch|\bmrfa\b/i", 'unit' => 'fuel', 'other' => ['pedal']],
        'torqueLimit' => ['name' => '/torque limit|drehmomentbegrenz|mengenbegrenz/i', 'unit' => 'fuel', 'other' => ['none', 'pressure']],
        'fullLoadLimit' => ['name' => '/full.?load (fuel|quantity)|turbo protection|vollastmenge/i', 'unit' => 'fuel', 'other' => ['none', 'pressure']],
        'smoke' => ['name' => '/smoke|rauch/i', 'unit' => 'fuel', 'other' => ['air']],
        'boost' => ['name' => '/boost pressure target|ladedruck.?soll|\bldrsoll/i', 'unit' => 'pressure', 'other' => ['fuel']],
        'boostLimit' => ['name' => '/boost pressure limit|boost.*max(imum)? limit|ladedruck.?begrenz/i', 'unit' => 'pressure', 'other' => ['pressure']],
        'duration' => ['name' => '/injection duration|ansteuerdauer|energizing/i', 'unit' => null, 'other' => []],
        'ldrxn' => ['name' => '/^LDRXN$/i', 'unit' => 'load', 'other' => ['none']],
        'kfmirl' => ['name' => '/^KFMIRL$/i', 'unit' => 'load', 'other' => ['any']],
        'pedal' => ['name' => '/^KFPED\b(?!R)|pedal map|pedal characteristic|fahrpedal.?kennfeld/i', 'unit' => 'load', 'other' => ['pedal']],
    ];

    /** Maps of a special function, diagnosis or correction that only look like a role by name. */
    private const EXCLUDED = '/launch|\bADB\b|_ADB|hard.?cut|monitor|diagnos|plausib|contamination|duration|timer|delay|debounce|zeit|dauer|'
        .'correction|weighting|factor|multiplier|gain|threshold|detection|disconnect|calibration|sensor|fallback|fault|'
        .'\bEGR\b|\bAGR\b|DPF|regenerat|cold start|kaltstart|warm.?up/i';

    /** Variants of the main map for some conditions (raised gear group, dynamic): ranked just below it. */
    private const SECONDARY = '/raised|\bupper\b|\bhigh\b|dynamic|transient/i';

    /** Variants that only apply in some conditions: ranked below the main map. */
    private const VARIANT = '/multi.?map|\(MM\)|\bMM\b|low range|alternativ|reverse|rückwärts|sport|\beco\b|#[2-9]/i';

    /** @var array<string, string> unit pattern per value kind */
    private const UNITS = [
        'fuel' => '/^$|^-$|mg|mm\^?3|mm³|hub|stroke/i',
        'pressure' => '/^$|^-$|mbar|hpa|bar|kpa/i',
        'load' => '/^$|^-$|%/',
    ];

    /** @var array<string, string> axis name/unit pattern per kind */
    private const AXES = [
        'pedal' => '/%|pedal|fahrpedal|wped/i',
        'air' => '/luft|\bair\b|air mass|luftmasse/i',
        'fuel' => '/mm\^?3|mm³|mg|quantity|menge|\bIQ\b|\bmi\b/i',
        'pressure' => '/hpa|mbar|\bbar\b|kpa|atm|pressure|druck/i',
    ];

    /** Names of maps that only exist on charged engines. */
    private const CHARGING = '/boost|ladedruck|turbo|wastegate|\bN75\b|KFLDRL|KFLDIMX|LDRPID|\bVTG\b/i';

    /**
     * Whether the engine is turbocharged, judged from the file: boost control maps, or an ME7 load
     * ceiling (LDRXN) well above what a naturally aspirated engine can fill. Null when unclear.
     *
     * @param  MapDef[]  $maps
     */
    public static function isTurbo(array $maps, Bin $bin, bool $diesel): ?bool
    {
        if (! $diesel) {
            $ldrxn = self::candidates($maps, $bin, 'ldrxn')[0] ?? null;
            if ($ldrxn !== null) {
                return max(array_merge(...(new MapValues($ldrxn, $bin))->grid)) > 120;
            }
        }
        foreach ($maps as $map) {
            if (preg_match(self::CHARGING, $map->name) && ! preg_match(self::EXCLUDED, $map->name)) {
                return true;
            }
        }

        return $diesel ? false : null;
    }

    /**
     * Picks the best usable map for each role.
     *
     * @param  MapDef[]  $maps
     * @param  string[]  $roles
     * @param  array<string, string>  $overrides  role => map id, or NONE to switch the role off
     * @return array<string, MapDef>
     */
    public static function assign(array $maps, Bin $bin, array $roles, array $overrides = []): array
    {
        $found = [];
        foreach ($roles as $role) {
            $group = self::group($maps, $bin, $role, $overrides);
            if ($group) {
                $found[$role] = $group[0];
            }
        }

        return $found;
    }

    /**
     * All maps a role covers, best first: the user's choice alone, or every qualifying map.
     *
     * @param  MapDef[]  $maps
     * @param  array<string, string>  $overrides
     * @return MapDef[]
     */
    public static function group(array $maps, Bin $bin, string $role, array $overrides = []): array
    {
        $override = $overrides[$role] ?? null;
        if ($override === self::NONE) {
            return [];
        }
        if ($override !== null) {
            foreach ($maps as $map) {
                if ($map->id === $override && self::values($map, $bin) !== null) {
                    return [$map];
                }
            }
        }

        return self::candidates($maps, $bin, $role);
    }

    /**
     * What each role resolved to, with the other candidates, for the editor's map pickers.
     *
     * @param  MapDef[]  $maps
     * @param  string[]  $roles
     * @param  array<string, string>  $overrides
     * @return array<string, array{id: ?string, name: ?string, overridden: bool, group: string[], candidates: array<int, array{id: string, name: string}>}>
     */
    public static function describe(array $maps, Bin $bin, array $roles, array $overrides = []): array
    {
        $out = [];
        foreach ($roles as $role) {
            $group = self::group($maps, $bin, $role, $overrides);
            $out[$role] = [
                'id' => $group[0]->id ?? null,
                'name' => $group[0]->name ?? null,
                'overridden' => isset($overrides[$role]),
                'group' => array_map(fn (MapDef $m): string => $m->name, $group),
                'candidates' => array_map(fn (MapDef $m): array => ['id' => $m->id, 'name' => $m->name], self::candidates($maps, $bin, $role)),
            ];
        }

        return $out;
    }

    /**
     * Every map that qualifies for a role, best first.
     *
     * @param  MapDef[]  $maps
     * @return MapDef[]
     */
    public static function candidates(array $maps, Bin $bin, string $role): array
    {
        $scored = [];
        foreach ($maps as $map) {
            if (self::qualifies($map, $bin, $role)) {
                $penalty = (preg_match(self::VARIANT, $map->name) ? 0.5 : 0.0) + (preg_match(self::SECONDARY, $map->name) ? 0.1 : 0.0);
                $scored[] = [$map->confidence - $penalty, $map];
            }
        }
        usort($scored, fn (array $a, array $b): int => [$b[0], $a[1]->address] <=> [$a[0], $b[1]->address]);

        return array_column($scored, 1);
    }

    private static function qualifies(MapDef $map, Bin $bin, string $role): bool
    {
        $spec = self::SPEC[$role];
        if (! preg_match($spec['name'], $map->name)) {
            return false;
        }
        if ($role === 'duration') {
            return $map->rows * $map->cols >= 4 && self::values($map, $bin) !== null;
        }
        // curves need a few breakpoints; 2x2 maps are placeholders of unused modes
        $isCurve = $map->rows === 1 || $map->cols === 1;
        if (preg_match(self::EXCLUDED, $map->name) || $map->rows * $map->cols < ($isCurve ? 4 : 6)) {
            return false;
        }
        if (! preg_match(self::UNITS[$spec['unit']], trim($map->unit))) {
            return false;
        }

        $values = self::values($map, $bin);
        if ($values === null || ! $values->hasRpmAxis() || ! self::otherAxisFits($values, $spec['other'])) {
            return false;
        }

        // fuel limits are tens of mg/stroke, never thousands
        if ($spec['unit'] === 'fuel') {
            $max = max(array_merge(...$values->grid));

            return $max >= 5 && $max <= 250;
        }

        return true;
    }

    /**
     * @param  string[]  $kinds
     */
    private static function otherAxisFits(MapValues $values, array $kinds): bool
    {
        if ($values->map->rows === 1 || $values->map->cols === 1) {
            return in_array('none', $kinds, true) || in_array('any', $kinds, true);
        }
        if (in_array('any', $kinds, true)) {
            return true;
        }

        $axis = $values->otherAxisDef();
        $label = trim(($axis->unit ?? '').' '.($axis->name ?? ''));
        if ($label === '') {
            // nothing to go on (e.g. an XDF without axis units): trust the name
            return true;
        }
        if (preg_match(self::AXES['air'], $label)) {
            return in_array('air', $kinds, true);
        }
        foreach (['pedal', 'pressure', 'fuel'] as $kind) {
            if (preg_match(self::AXES[$kind], $label)) {
                return in_array($kind, $kinds, true);
            }
        }

        return false;
    }

    /** Values of a map when it lies inside the file and its axes are ascending, else null. */
    private static function values(MapDef $map, Bin $bin): ?MapValues
    {
        try {
            $values = new MapValues($map, $bin);
        } catch (\RangeException) {
            return null;
        }

        $usable = ($map->cols < 2 || MapValues::isAscending($values->x))
            && ($map->rows < 2 || MapValues::isAscending($values->y));

        return $usable ? $values : null;
    }
}
