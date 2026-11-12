<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Maps\AxisScanner;
use App\Ecu\Maps\DieselClassifier;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapValues;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

/**
 * Big-endian EDC16C35 layouts (BMW): n m X[n] Y[m] Z with Z stored per point of the first axis,
 * pedal axes ending at 8192, per-gear limiters with a 0..7 gear axis, padding that repeats a
 * curve's count, and maps that sit back to back without being an injection duration set.
 */
class Edc16MapDetectionTest extends TestCase
{
    private const RPM = [500, 750, 1000, 1250, 1500, 1750, 2000, 2250, 2500, 3000, 3500, 4000, 4500, 5000];

    private SyntheticBin $synthetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->synthetic = new SyntheticBin(0x8000);
    }

    public function test_values_are_stored_per_point_of_the_first_axis(): void
    {
        $pedal = [0, 819, 1065, 1638, 2458, 4096, 5734, 8192];
        // per rpm point, its 8 pedal values: request rises with pedal and falls slightly with rpm
        $this->mapB(0x1000, self::RPM, $pedal, fn (int $r, int $p): int => (int) round($pedal[$p] / 8192 * (4554 - $r * 50)));

        $map = $this->only();
        $this->assertSame("Driver's wish", $map->name);
        $this->assertSame([14, 8], [$map->rows, $map->cols]);
        $values = new MapValues($map, $this->bin());
        $this->assertSame(500.0, $values->y[0]);
        $this->assertSame(100.0, $values->x[array_key_last($values->x)]);
        $this->assertSame(45.54, $values->grid[0][7]);
        $this->assertSame([0.0, 4.55, 5.92, 9.11, 13.66, 22.77, 31.88, 45.54], $values->grid[0]);
    }

    public function test_per_gear_limiter_with_index_axis(): void
    {
        $rpm = [600, 800, 1000, 1300, 1500, 1750, 2000, 2500, 3000, 3500, 4000, 4500, 5060];
        $this->mapB(0x1000, $rpm, range(0, 7), fn (int $r, int $g): int => 3000 + $g * 100 + min($r, 8) * 100);

        $map = $this->only();
        $this->assertSame('Torque limiter (per gear)', $map->name);
        $this->assertSame('Gear', $map->x->name);
    }

    public function test_index_axis_alone_is_not_a_map(): void
    {
        $this->mapB(0x1000, range(0, 5), range(0, 3), fn (int $a, int $b): int => 1000 + $a + $b);

        $layouts = array_column((new AxisScanner($this->bin(), be: true))->scan(), 'layout');
        $this->assertNotContains('B', $layouts);
    }

    public function test_curve_after_repeated_count_padding(): void
    {
        $axis = [500, 600, 800, 1000, 1250, 2000, 3000, 4000, 4400, 5000];
        $this->synthetic->words(0x1000, [...array_fill(0, 6, 10), ...$axis, 0, 1500, 2600, 3300, 3800, 4470, 4400, 4100, 3600, 3000], be: true);

        $map = $this->only();
        $this->assertSame(0x1000 + (6 + 10) * 2, $map->address);
        $this->assertSame('Torque limiter (IQ vs rpm)', $map->name);
    }

    public function test_small_false_hit_in_a_header_gives_way_to_the_real_map(): void
    {
        // "2 2 3 1" ahead of the real 16x16 header reads as a tiny B map
        $rpm = [800, 1000, 1250, 1500, 1750, 2000, 2250, 2500, 2750, 3000, 3250, 3500, 3750, 4000, 4250, 5000];
        $iq = [0, 400, 600, 800, 1000, 1200, 1500, 1800, 2000, 2500, 3000, 3500, 4000, 4500, 5000, 5500];
        $this->synthetic->words(0x1000, [2, 3, 1, 16], be: true);
        $this->mapB(0x1006, $rpm, $iq, fn (int $r, int $q): int => 3200 + $r * 500 + $q * 300);

        $map = $this->only();
        $this->assertSame([16, 16], [$map->rows, $map->cols]);
        $this->assertSame('Rail pressure target', $map->name);
    }

    public function test_neighbouring_maps_are_not_an_injection_duration_set(): void
    {
        // EDC15 only treats runs of 100+ cell maps as a duration set
        $iq = [0, 500, 1000, 1500, 2000, 2500, 3000, 4000, 5000, 6000];
        $rpm = [500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000];
        $at = 0x1000;
        foreach ([0, 1, 2] as $n) {
            $at = $this->mapB($at, $iq, $rpm, fn (int $q, int $r): int => 1000 + $q * 150 + $r * 10 + $n) + 2 * count($iq) * count($rpm);
        }

        $names = array_map(fn (MapDef $m): string => $m->name, $this->classify(durationSets: false));
        $this->assertSame(['Boost pressure target #1', 'Boost pressure target #2', 'Boost pressure target #3'], $names);
        // EDC15 keeps its back-to-back duration set detection
        $this->assertStringStartsWith('Injection duration', $this->classify(durationSets: true)[0]->name);
    }

    public function test_part_load_curves_and_factors_are_not_torque_limiters(): void
    {
        $partLoad = [0, 500, 600, 700, 800, 1000, 1200, 1400, 1600, 1800, 2000, 2200, 2400, 2600, 2800, 3000];
        $this->synthetic->words(0x1000, [16, ...$partLoad, ...array_map(fn (int $i): int => $i * 250, range(0, 15))], be: true);
        $this->synthetic->words(0x1100, [8, 0, 800, 1600, 2400, 3200, 4000, 5000, 6000, ...array_fill(0, 8, 8192)], be: true);

        $names = array_map(fn (MapDef $m): string => $m->name, $this->classify());
        $this->assertNotContains('Torque limiter (IQ vs rpm)', $names);
        $this->assertContains('Correction factor curve', $names);
    }

    public function test_axes_longer_than_32_points(): void
    {
        $iq = array_map(fn (int $i): int => $i * 190, range(0, 39));
        $rail = [1200, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000, 11000, 12000, 13000, 14000, 15000, 16000];
        $this->mapB(0x1000, $iq, $rail, fn (int $q, int $p): int => min(5000, 100 + $q * 120 - $p * 20));

        $map = $this->only();
        $this->assertSame([40, 16], [$map->rows, $map->cols]);
        $this->assertSame('Injection duration', $map->name);
    }

    /**
     * Writes n m X Y Z with Z per point of the first axis and returns the data address.
     *
     * @param  int[]  $first
     * @param  int[]  $second
     * @param  callable(int, int): int  $value  (first index, second index)
     */
    private function mapB(int $at, array $first, array $second, callable $value): int
    {
        $data = [];
        foreach (array_keys($first) as $i) {
            foreach (array_keys($second) as $j) {
                $data[] = $value($i, $j);
            }
        }
        $this->synthetic->words($at, [count($first), count($second), ...$first, ...$second, ...$data], be: true);

        return $at + (2 + count($first) + count($second)) * 2;
    }

    private function bin(): Bin
    {
        return new Bin($this->synthetic->data());
    }

    /**
     * @return MapDef[]
     */
    private function classify(bool $durationSets = false): array
    {
        return (new DieselClassifier($durationSets))->classify((new AxisScanner($this->bin(), be: true))->scan());
    }

    private function only(): MapDef
    {
        $maps = $this->classify();
        $this->assertCount(1, $maps, implode(', ', array_map(fn (MapDef $m): string => $m->name, $maps)));

        return $maps[0];
    }
}
