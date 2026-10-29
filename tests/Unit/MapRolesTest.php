<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapRoles;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

/**
 * Role matching against the map names of a BMW DDE4 (EDC15C) XDF, which holds several maps per
 * role plus launch control, multimap copies and diagnosis maps with similar names.
 */
class MapRolesTest extends TestCase
{
    private const RPM = [800, 1250, 1750, 2250, 2750, 3250, 3750, 4250];

    private SyntheticBin $synthetic;

    /** @var MapDef[] */
    private array $maps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->synthetic = new SyntheticBin(0x8000);
        $at = 0x100;
        $curve = function (string $name) use (&$at): void {
            $this->maps[] = $this->synthetic->map($name, $at, $name, self::RPM, '', '1/min', null, [[4000, 5000, 6000, 7000, 7000, 6500, 6000, 5000]], 0.01, 'mm^3');
            $at += 0x100;
        };
        $map = function (string $name, array $other, string $otherUnit, int $value, string $unit = 'mm^3', float $factor = 0.01) use (&$at): void {
            $rows = array_map(fn (): array => array_fill(0, count($other), $value), self::RPM);
            $this->maps[] = $this->synthetic->map($name, $at, $name, $other, '', $otherUnit, self::RPM, $rows, $factor, $unit);
            $at += 0x200;
        };

        $curve('Launch torque limiter (mrwADB_KL) - HARDCUT (MM)');
        $curve('Launch torque limiter - fuel quantity limit (mrwADB_KL)');
        $curve('Torque limiter raised (mrwBDBH_KL) - HARDCUT (MM)');
        $curve('Torque limiter - fuel quantity limit, raised gear-based variant (mrwBDBH_KL)');
        $curve('Torque limiter - fuel quantity limit, normal gear-based variant (mrwBDBN_KL)');
        $curve('Torque limiter - fuel quantity limit, low range (mrwBDBLRKL)');

        $pedal = [0, 2000, 4000, 6000, 8000, 10000];
        $map("Driver's wish - requested fuel quantity, low range (mrwFVLR_KF)", $pedal, '%', 7000);
        $map("Driver's wish - requested fuel quantity, lower vehicle speed range (mrwFVHU_KF)", $pedal, '%', 7500);

        $air = [300, 500, 700, 900, 1100];
        $map('Smoke limiter - dynamic fuel limit from air mass (mrwBRDY_KF)', $air, 'mg/Hub Luft', 8000);
        $map('Smoke limiter - static fuel limit from air mass (mrwBRA_KF)', $air, 'mg/Hub Luft', 8000);
        $map('Smoke limiter - atmospheric pressure correction map (mrwBRAkAKF)', $air, 'mg/Hub Luft', 8000);
        $map('Smoke limiter - boost hose disconnection detection map (mrwPBRALKF)', [1000, 1500, 2000, 2500, 3000], 'hPa', 6000);

        $iq = [0, 2000, 4000, 6000, 8000];
        $map('Boost pressure target - economy mode (ldwSWoekKF)', $iq, 'mm^3', 2200, 'hPa', 1.0);
        $map('Boost pressure target - maximum limit (ldwMXWKF)', [700, 800, 900, 1000, 1100], 'hPa', 2350, 'hPa', 1.0);

        // a single value in µs named after the smoke limiter, picked by mistake before
        $this->maps[] = new MapDef('MAF contamination monitoring - smoke-limit duration (arwLMVtRau)', 0x7000, unit: 'us', confidence: 1.0, id: 'arwLMVtRau');
    }

    public function test_main_maps_win_over_launch_multimap_and_variants(): void
    {
        $roles = MapRoles::assign($this->maps, $this->bin(), MapRoles::DIESEL);

        $this->assertSame('Torque limiter - fuel quantity limit, normal gear-based variant (mrwBDBN_KL)', $roles['torqueLimit']->name);
        $this->assertSame("Driver's wish - requested fuel quantity, lower vehicle speed range (mrwFVHU_KF)", $roles['wish']->name);
        $this->assertSame('Smoke limiter - static fuel limit from air mass (mrwBRA_KF)', $roles['smoke']->name);
        $this->assertSame('Boost pressure target - economy mode (ldwSWoekKF)', $roles['boost']->name);
        $this->assertSame('Boost pressure target - maximum limit (ldwMXWKF)', $roles['boostLimit']->name);
    }

    public function test_groups_hold_every_real_map_of_a_role_and_nothing_else(): void
    {
        $bin = $this->bin();

        $this->assertSame([
            'Torque limiter - fuel quantity limit, normal gear-based variant (mrwBDBN_KL)',
            'Torque limiter - fuel quantity limit, raised gear-based variant (mrwBDBH_KL)',
            'Torque limiter - fuel quantity limit, low range (mrwBDBLRKL)',
        ], $this->names(MapRoles::group($this->maps, $bin, 'torqueLimit')));
        $this->assertSame([
            'Smoke limiter - static fuel limit from air mass (mrwBRA_KF)',
            'Smoke limiter - dynamic fuel limit from air mass (mrwBRDY_KF)',
        ], $this->names(MapRoles::group($this->maps, $bin, 'smoke')));
        $this->assertSame(['Boost pressure target - economy mode (ldwSWoekKF)'], $this->names(MapRoles::group($this->maps, $bin, 'boost')));
    }

    public function test_user_can_pick_a_map_or_switch_a_role_off(): void
    {
        $bin = $this->bin();
        $raised = 'Torque limiter - fuel quantity limit, raised gear-based variant (mrwBDBH_KL)';

        $roles = MapRoles::assign($this->maps, $bin, MapRoles::DIESEL, ['torqueLimit' => $raised, 'smoke' => MapRoles::NONE]);

        $this->assertSame($raised, $roles['torqueLimit']->name);
        $this->assertArrayNotHasKey('smoke', $roles);
        $described = MapRoles::describe($this->maps, $bin, ['torqueLimit'], ['torqueLimit' => $raised])['torqueLimit'];
        $this->assertTrue($described['overridden']);
        $this->assertSame([$raised], $described['group']);
        $this->assertCount(3, $described['candidates']);
    }

    private function bin(): Bin
    {
        return new Bin($this->synthetic->data());
    }

    /**
     * @param  MapDef[]  $maps
     * @return string[]
     */
    private function names(array $maps): array
    {
        return array_map(fn (MapDef $m): string => $m->name, $maps);
    }
}
