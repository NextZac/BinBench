<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Diff\BinDiff;
use App\Ecu\Diff\TuneReview;
use App\Ecu\Dyno\EngineSpec;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapValues;
use App\Ecu\Maps\MapWriter;
use App\Ecu\Tuning\Stage1Options;
use App\Ecu\Tuning\Stage1Planner;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class TuneReviewTest extends TestCase
{
    private const RPM = [1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500];

    private const OK_CHECKSUM = ['module' => 'test', 'canFix' => true, 'blocks' => [], 'ok' => true];

    private Bin $original;

    /** @var array<string, MapDef> */
    private array $maps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $synthetic = new SyntheticBin(0x4000);
        $rows = fn (callable $cell, array $axis): array => array_map(fn (): array => array_map($cell, $axis), self::RPM);
        $pedal = [0, 2000, 5000, 8000, 10000];
        $air = [3000, 5000, 7000, 9000, 11000];
        $iq = [0, 2000, 4000, 6000];

        $this->maps = [
            'wish' => $synthetic->map('wish', 0x100, "Driver's wish", $pedal, 'Pedal position', '%', self::RPM, $rows(fn (int $p): int => (int) ($p * 0.6), $pedal)),
            'limiter' => $synthetic->map('limiter', 0x400, 'Torque limiter (IQ vs rpm)', self::RPM, 'Engine speed', '1/min', null, [[3000, 4000, 5000, 5000, 5000, 4800, 4500, 4000]]),
            'smoke' => $synthetic->map('smoke', 0x600, 'Smoke limiter (air mass)', $air, 'Air mass', 'mg/st', self::RPM, $rows(fn (int $a): int => (int) ($a * 0.6), $air), 0.01, 'mg/st', 0.1),
            'boost' => $synthetic->map('boost', 0xA00, 'Boost pressure target', $iq, 'Fuel quantity', 'mg/st', self::RPM, $rows(fn (int $q): int => 1000 + (int) ($q / 4), $iq), 1.0, 'mbar', 0.01),
            'boostLimit' => $synthetic->map('boostLimit', 0xE00, 'Boost pressure limiter', [600, 800, 1000, 1100], 'Atm. pressure', 'mbar', self::RPM, $rows(fn (): int => 2600, [1, 2, 3, 4]), 1.0, 'mbar', 1.0),
        ];
        $this->original = new Bin($synthetic->data());
    }

    public function test_reports_no_verdict_without_changes(): void
    {
        $this->assertSame('none', $this->review($this->original)['verdict']);
    }

    public function test_stage1_proposal_passes_as_clean(): void
    {
        $plan = (new Stage1Planner)->plan(array_values($this->maps), $this->diesel(), $this->original, $this->original, new Stage1Options);
        $review = $this->review((new Stage1Planner)->apply($this->original, $plan['proposals'], array_values($this->maps)));

        $this->assertSame('clean', $review['verdict'], json_encode($review['findings']));
    }

    public function test_large_fuel_increase_is_risky(): void
    {
        $tuned = $this->scaled($this->scaled($this->scaled($this->original, 'limiter', 1.4), 'wish', 1.4), 'smoke', 1.4);
        $tuned = $this->scaled($tuned, 'boost', 1.15);

        $review = $this->review($tuned);

        $this->assertSame('risky', $review['verdict']);
        $this->assertSame('Full-load fuel raised by 40 %', $review['findings'][0]['title']);
        $this->assertSame(TuneReview::DANGER, $review['findings'][0]['severity']);
    }

    public function test_flags_badly_made_tune(): void
    {
        // torque limiter raised alone: the driver's wish still caps it, smoke and boost untouched
        $tuned = $this->scaled($this->original, 'limiter', 1.3);
        $boost = new MapValues($this->maps['boost'], $tuned);
        $grid = $boost->grid;
        $grid[4][2] += 1500;
        $tuned = MapWriter::write($tuned, $this->maps['boost'], $grid)->withPatch(0x3000, "\x01\x02");

        $titles = array_column($this->review($tuned)['findings'], 'title');

        $this->assertContains("Driver's wish caps the raised torque limit", $titles);
        $this->assertContains('Boost pressure target: spike at row 4, column 2', $titles);
        $this->assertContains('Boost target above the boost limiter', $titles);
        $this->assertContains('2 bytes changed outside known maps', $titles);
    }

    public function test_more_fuel_without_boost_or_smoke_needs_a_look(): void
    {
        $tuned = $this->scaled($this->scaled($this->original, 'limiter', 1.2), 'wish', 1.2);

        $review = $this->review($tuned);
        $titles = array_column($review['findings'], 'title');

        $this->assertSame('check', $review['verdict']);
        $this->assertContains('Smoke limiter not adjusted', $titles);
        $this->assertContains('More fuel without more boost', $titles);
    }

    public function test_values_at_the_storage_limit_are_dangerous(): void
    {
        $limiter = new MapValues($this->maps['limiter'], $this->original);
        $grid = $limiter->grid;
        $grid[0][3] = 655.35;

        $review = $this->review(MapWriter::write($this->original, $this->maps['limiter'], $grid));

        $this->assertSame('risky', $review['verdict']);
        $this->assertContains('Torque limiter (IQ vs rpm): values at the storage limit', array_column($review['findings'], 'title'));
    }

    public function test_checksum_that_cannot_be_fixed_is_dangerous(): void
    {
        $tuned = $this->scaled($this->original, 'wish', 1.05);

        $review = $this->review($tuned, ['module' => 'test', 'canFix' => false, 'blocks' => [], 'ok' => false]);

        $this->assertSame('risky', $review['verdict']);
        $this->assertSame('Checksums do not match', $review['findings'][0]['title']);
    }

    public function test_diff_reports_changed_maps_axes_and_unmapped_bytes(): void
    {
        $tuned = $this->scaled($this->original, 'limiter', 1.1)
            ->withPatch($this->maps['wish']->x->address + 2, pack('v', 2500))
            ->withPatch(0x3000, "\xAA\xBB\xCC")
            ->withPatch(0x3008, "\xDD");

        $diff = BinDiff::compare($this->original, $tuned, array_values($this->maps));
        $byId = array_column($diff['maps'], null, 'id');

        $this->assertSame(['wish', 'limiter'], array_column($diff['maps'], 'id'));
        $this->assertTrue($byId['wish']['axisChanged']);
        $this->assertSame(0, $byId['wish']['changedCells']);
        $this->assertSame(8, $byId['limiter']['changedCells']);
        $this->assertEqualsWithDelta(10.0, $byId['limiter']['meanChangePct'], 0.1);
        $this->assertSame([['start' => 0x3000, 'end' => 0x3009, 'length' => 9]], $diff['unmapped']);
    }

    /**
     * @param  array<string, mixed>  $checksum
     * @return array{verdict: string, findings: array<int, array<string, mixed>>}
     */
    private function review(Bin $tuned, array $checksum = self::OK_CHECKSUM): array
    {
        $maps = array_values($this->maps);

        return (new TuneReview)->review($maps, $this->diesel(), $this->original, $tuned, BinDiff::compare($this->original, $tuned, $maps), $checksum);
    }

    private function scaled(Bin $bin, string $key, float $factor): Bin
    {
        $values = new MapValues($this->maps[$key], $bin);

        return MapWriter::write($bin, $this->maps[$key], array_map(fn (array $row): array => array_map(fn (float $v): float => $v * $factor, $row), $values->grid));
    }

    private function diesel(): EngineSpec
    {
        return new EngineSpec(2.0, 4, true, null, false);
    }
}
