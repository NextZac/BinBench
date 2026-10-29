<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Dyno\EngineSpec;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapRoles;
use App\Ecu\Maps\MapValues;
use App\Ecu\Tuning\Stage1Options;
use App\Ecu\Tuning\Stage1Planner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class Stage1PlannerTest extends TestCase
{
    private const RPM = [1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500];

    private const PEDAL = [0, 2000, 5000, 8000, 10000];

    private const AIR = [3000, 5000, 7000, 9000, 11000];

    private const IQ = [0, 2000, 4000, 6000];

    private SyntheticBin $synthetic;

    /** @var MapDef[] */
    private array $maps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->synthetic = new SyntheticBin(0x4000);
        $rows = fn (callable $cell, array $axis): array => array_map(fn (): array => array_map($cell, $axis), self::RPM);

        $this->maps = [
            $this->synthetic->map('wish', 0x100, "Driver's wish", self::PEDAL, 'Pedal position', '%', self::RPM, $rows(fn (int $p): int => (int) ($p * 0.6), self::PEDAL)),
            $this->synthetic->map('limiter', 0x400, 'Torque limiter (IQ vs rpm)', self::RPM, 'Engine speed', '1/min', null, [[3000, 4000, 5000, 5000, 5000, 4800, 4500, 4000]]),
            $this->synthetic->map('smoke', 0x600, 'Smoke limiter (air mass)', self::AIR, 'Air mass', 'mg/st', self::RPM, $rows(fn (int $a): int => (int) ($a * 0.5), self::AIR), 0.01, 'mg/st', 0.1),
            $this->synthetic->map('boost', 0xA00, 'Boost pressure target', self::IQ, 'Fuel quantity', 'mg/st', self::RPM, $rows(fn (int $q): int => 1000 + (int) ($q / 4), self::IQ), 1.0, 'mbar', 0.01),
            $this->synthetic->map('egr', 0xE00, 'EGR air mass target', self::IQ, 'Fuel quantity', 'mg/st', self::RPM, $rows(fn (): int => 4000, self::IQ), 0.1, 'mg/st', 0.01),
        ];
    }

    public function test_raises_torque_limiter_by_the_fuel_increase(): void
    {
        $plan = $this->plan();

        $this->assertTrue($plan['available']);
        $limiter = $this->proposal($plan, 'torqueLimit');
        $this->assertSame([34.5, 46.0, 57.5, 57.5, 57.5, 55.2, 51.75, 46.0], $limiter['values'][0]);
        $this->assertSame(8, $limiter['changedCells']);
    }

    public function test_drivers_wish_changes_only_towards_full_pedal(): void
    {
        $wish = $this->proposal($this->plan(), 'wish');

        // pedal 0, 20, 50 % unchanged; 80 % gets half the increase; 100 % the full 15 %
        $this->assertSame([0.0, 12.0, 30.0, 51.6, 69.0], $wish['values'][0]);
    }

    public function test_smoke_limiter_keeps_low_air_mass_stock(): void
    {
        $smoke = $this->proposal($this->plan(), 'smoke');

        $this->assertSame(15.0, $smoke['values'][2][0]);
        $this->assertSame(25.0, $smoke['values'][2][1]);
        $this->assertSame(63.25, $smoke['values'][2][4]);
    }

    public function test_boost_is_capped_and_left_alone_at_low_rpm(): void
    {
        $plan = $this->plan(new Stage1Options(boostIncrease: 20, maxBoost: 2600));
        $boost = $this->proposal($plan, 'boost');

        $this->assertSame([1000.0, 1500.0, 2000.0, 2500.0], $boost['values'][0]);
        $this->assertSame(2600.0, $boost['values'][2][3]);
        $this->assertSame(2600.0, $boost['after']['max']);
        $this->assertStringContainsString('2600 mbar cap', implode(' ', $plan['warnings']));

        // a stock value already above the cap is kept, never lowered
        $this->assertSame(2500.0, $this->proposal($this->plan(new Stage1Options(maxBoost: 2400)), 'boost')['values'][2][3]);
    }

    public function test_never_touches_emission_maps(): void
    {
        $plan = $this->plan(new Stage1Options(fuelIncrease: 40, boostIncrease: 25));

        $this->assertNotContains('EGR air mass target', array_column($plan['proposals'], 'name'));
        $this->assertContains('Emission maps (EGR, DPF, SCR, lambda) are left unchanged.', $plan['notes']);
    }

    public function test_max_fuel_caps_every_fuel_map(): void
    {
        $plan = $this->plan(new Stage1Options(fuelIncrease: 30, maxFuel: 55));

        $this->assertSame(55.0, $this->proposal($plan, 'torqueLimit')['after']['max']);
        $this->assertSame([], array_filter(array_merge(...$this->proposal($plan, 'smoke')['values']), fn (float $v): bool => $v > 55.0));
        // stock values above the cap (60 mg/st at full pedal) are never lowered
        $this->assertSame(60.0, $this->proposal($plan, 'wish')['after']['max']);
    }

    public function test_applied_proposal_reads_back_identically(): void
    {
        $plan = $this->plan();
        $bin = new Bin($this->synthetic->data());
        $roles = MapRoles::assign($this->maps, $bin, MapRoles::DIESEL);

        $tuned = (new Stage1Planner)->apply($bin, $plan['proposals'], $roles);

        foreach ($plan['proposals'] as $proposal) {
            $this->assertSame($proposal['values'], array_map(fn (array $row): array => array_map(fn (float $v): float => round($v, 6), $row),
                (new MapValues($roles[$proposal['role']], $tuned))->grid));
        }
        $this->assertGreaterThan($plan['dyno']['stock']['peakPower']['value'], $plan['dyno']['modified']['peakPower']['value']);
    }

    public function test_warns_about_torque_limit_and_short_duration_axis(): void
    {
        $rail = [2000, 6000, 10000, 14000, 18000];
        $this->maps[] = $this->synthetic->map('duration', 0x1200, 'Injection duration', self::IQ, 'Fuel quantity', 'mg/st', null, [[0, 400, 800, 1200]], 1.0, 'raw', 0.01);
        $this->maps[] = $this->synthetic->map('duration2', 0x1300, 'Injection duration #2', $rail, 'Rail pressure', 'bar', null, [[0, 1, 2, 3, 4]], 1.0, 'raw');

        $plan = $this->plan(new Stage1Options(maxTorque: 250));
        $warnings = implode(' ', $plan['warnings']);

        $this->assertStringContainsString('exceeds the 250 Nm', $warnings);
        $this->assertStringContainsString('Injection duration ends at 60.0 mg/st', $warnings);
        $this->assertStringNotContainsString('Injection duration #2', $warnings);
    }

    public function test_unavailable_without_torque_maps(): void
    {
        $plan = (new Stage1Planner)->plan([$this->maps[4]], $this->diesel(), new Bin($this->synthetic->data()), new Bin($this->synthetic->data()), new Stage1Options);

        $this->assertFalse($plan['available']);
        $this->assertSame([], $plan['proposals']);
    }

    public function test_petrol_load_is_capped_at_max_load(): void
    {
        $synthetic = new SyntheticBin(0x1000);
        $maps = [$synthetic->map('ldrxn', 0x100, 'LDRXN', [1500, 3000, 4500, 6000], 'nmot_w', 'rpm', null, [[6000, 7000, 7500, 7000]], 0.023438, '%')];
        $bin = new Bin($synthetic->data());

        $plan = (new Stage1Planner)->plan($maps, new EngineSpec(1.8, 4, false, null, false), $bin, $bin, new Stage1Options(loadIncrease: 15, maxLoad: 180));
        $ldrxn = $this->proposal($plan, 'ldrxn');

        $this->assertEqualsWithDelta(180.0, $ldrxn['after']['max'], 0.03);
        $this->assertEqualsWithDelta(6000 * 0.023438 * 1.15, $ldrxn['values'][0][0], 0.03);
        $this->assertStringContainsString('KFMIRL was not found', implode(' ', $plan['warnings']));
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(?Stage1Options $options = null): array
    {
        $bin = new Bin($this->synthetic->data());

        return (new Stage1Planner)->plan($this->maps, $this->diesel(), $bin, $bin, $options ?? new Stage1Options);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function proposal(array $plan, string $role): array
    {
        foreach ($plan['proposals'] as $proposal) {
            if ($proposal['role'] === $role) {
                return $proposal;
            }
        }
        $this->fail("No proposal for {$role}.");
    }

    private function diesel(): EngineSpec
    {
        return new EngineSpec(2.0, 4, true, null, false);
    }
}
