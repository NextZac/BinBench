<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Dyno\DynoEstimator;
use App\Ecu\Dyno\EngineSpec;
use App\Ecu\Maps\MapDef;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class DynoEstimatorTest extends TestCase
{
    private const RPM = [1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500];

    private const TORQUE_LIMITER = [3000, 4000, 5000, 5000, 5000, 4800, 4500, 4000];

    private SyntheticBin $synthetic;

    /** @var MapDef[] */
    private array $maps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->synthetic = new SyntheticBin(0x4000);

        // driver's wish: 60 mg/st at full pedal everywhere, so the torque limiter is what limits
        $pedal = [0, 2000, 5000, 8000, 10000];
        $this->maps[] = $this->synthetic->map('wish', 0x100, "Driver's wish", $pedal, 'Pedal position', '%', self::RPM,
            array_map(fn (): array => array_map(fn (int $p): int => (int) ($p * 0.6), $pedal), self::RPM));
        $this->maps[] = $this->synthetic->map('limiter', 0x400, 'Torque limiter (IQ vs rpm)', self::RPM, 'Engine speed', '1/min', null, [self::TORQUE_LIMITER]);
    }

    public function test_torque_follows_the_lowest_fuel_limit(): void
    {
        $result = (new DynoEstimator)->estimate($this->maps, $this->diesel(), new Bin($this->synthetic->data()));

        $this->assertTrue($result['available']);
        $stock = $result['stock'];
        $at2000 = array_search(2000, $stock['rpm'], true);
        $this->assertSame(50.0, $stock['fuel'][$at2000]);
        $this->assertSame('Torque limiter', $stock['limiter'][$at2000]);
        // 50 mg x 4 cylinders x 42.6 MJ/kg x 0.40 efficiency / 4π
        $this->assertEqualsWithDelta(271.2, $stock['torque'][$at2000], 0.5);
        $this->assertSame(1000, $stock['rpm'][0]);
        $this->assertSame(4500, end($stock['rpm']));
    }

    public function test_raised_limiter_raises_modified_curve_only(): void
    {
        $stock = new Bin($this->synthetic->data());
        $raised = array_map(fn (int $v): int => (int) round($v * 1.2), self::TORQUE_LIMITER);
        $modified = new Bin((clone $this->synthetic)->words($this->maps[1]->address, $raised)->data());

        $result = (new DynoEstimator)->estimate($this->maps, $this->diesel(), $stock, $modified);

        $this->assertEqualsWithDelta($result['stock']['peakTorque']['value'] * 1.2, $result['modified']['peakTorque']['value'], 0.5);
        $this->assertGreaterThan($result['stock']['peakPower']['value'], $result['modified']['peakPower']['value']);
    }

    public function test_smoke_limiter_caps_fuel_by_air_from_boost_target(): void
    {
        // boost target: 2000 mbar everywhere; smoke limiter allows 4 mg fuel per 100 mg air
        $iq = [0, 2000, 4000, 6000];
        $air = [3000, 5000, 7000, 9000, 11000];
        $this->maps[] = $this->synthetic->map('boost', 0x800, 'Boost pressure target', $iq, 'Fuel quantity', 'mg/st', self::RPM,
            array_map(fn (): array => array_fill(0, count($iq), 2000), self::RPM), 1.0, 'mbar');
        $this->maps[] = $this->synthetic->map('smoke', 0xC00, 'Smoke limiter (air mass)', $air, 'Air mass', 'mg/st', self::RPM,
            array_map(fn (): array => array_map(fn (int $a): int => (int) ($a * 0.4), $air), self::RPM), 0.01, 'mg/st', 0.1);

        $result = (new DynoEstimator)->estimate($this->maps, $this->diesel(), new Bin($this->synthetic->data()));

        // 0.5 l cylinder at 2000 mbar, 323 K, VE 0.9 -> about 971 mg air -> about 38.8 mg fuel
        $at2000 = array_search(2000, $result['stock']['rpm'], true);
        $this->assertSame('Smoke limiter', $result['stock']['limiter'][$at2000]);
        $this->assertEqualsWithDelta(38.8, $result['stock']['fuel'][$at2000], 0.2);
        $this->assertSame('Smoke limiter (air mass)', $result['roles']['smoke']['name']);
    }

    public function test_stock_power_calibrates_both_curves(): void
    {
        $spec = $this->diesel();
        $spec->stockPower = 150.0;
        $bin = new Bin($this->synthetic->data());

        $result = (new DynoEstimator)->estimate($this->maps, $spec, $bin, $bin);

        $this->assertTrue($result['calibrated']);
        $this->assertEqualsWithDelta(150.0, $result['stock']['peakPower']['value'], 0.2);
        $this->assertEqualsWithDelta(150.0, $result['modified']['peakPower']['value'], 0.2);
    }

    public function test_unavailable_without_limiting_maps(): void
    {
        $result = (new DynoEstimator)->estimate([], $this->diesel(), new Bin($this->synthetic->data()));

        $this->assertFalse($result['available']);
        $this->assertNull($result['stock']);
        $this->assertStringContainsString("driver's wish", $result['reason']);
    }

    public function test_petrol_load_is_limited_by_ldrxn_and_kfmirl(): void
    {
        $synthetic = new SyntheticBin(0x2000);
        $rpm = [1500, 2500, 3500, 4500, 5500, 6500];
        $mi = [0, 16384, 32768, 49152, 65535];
        $this->synthetic = $synthetic;
        $maps = [
            // 150 % load ceiling (raw / 0.023438)
            $this->synthetic->map('ldrxn', 0x100, 'LDRXN', $rpm, 'nmot_w', 'rpm', null, [array_fill(0, 6, 6400)], 0.023438, '%'),
            // KFMIRL reaches 120 % at full request, below LDRXN
            $this->synthetic->map('kfmirl', 0x300, 'KFMIRL', $mi, 'mi (torque request)', '%', $rpm,
                array_map(fn (): array => array_map(fn (int $m): int => (int) ($m / 65535 * 5120), $mi), $rpm), 0.023438, '%', 0.0015259),
        ];

        $result = (new DynoEstimator)->estimate($maps, new EngineSpec(1.8, 4, false, null, false), new Bin($synthetic->data()));

        $this->assertTrue($result['available']);
        $this->assertSame('Torque request (KFMIRL)', $result['stock']['limiter'][0]);
        $this->assertEqualsWithDelta(120.0, $result['stock']['fuel'][0], 0.1);
        $this->assertSame(6500, end($result['stock']['rpm']));
    }

    public function test_engine_spec_reads_ident_and_prefers_user_input(): void
    {
        $fromIdent = EngineSpec::fromIdent(['engine' => 'R4 1.9l EDC', 'fuel' => 'Diesel'], 'edc15');
        $this->assertSame(1.9, $fromIdent->displacement);
        $this->assertSame(4, $fromIdent->cylinders);
        $this->assertFalse($fromIdent->assumed);

        $guessed = EngineSpec::fromIdent([], 'me7');
        $this->assertTrue($guessed->assumed);
        $this->assertFalse($guessed->diesel);

        $overridden = EngineSpec::fromIdent([], 'edc16', ['displacement' => 3.0, 'cylinders' => 6]);
        $this->assertSame(3.0, $overridden->displacement);
        $this->assertSame(6, $overridden->cylinders);
        $this->assertFalse($overridden->assumed);
    }

    private function diesel(): EngineSpec
    {
        return new EngineSpec(2.0, 4, true, null, false);
    }
}
