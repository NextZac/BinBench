<?php

namespace Tests\Unit;

use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapDefValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MapDefValidatorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function map(): array
    {
        $map = new MapDef('Boost pressure target', 0x1000, 8, 16, factor: 0.1, unit: 'mbar');
        $map->x = new Axis(16, 0x0F00, unit: '1/min');
        $map->y = new Axis(8, null, values: [0, 10, 20, 30, 40, 50, 60, 70]);

        return $map->toArray();
    }

    public function test_accepts_a_map_produced_by_binbench(): void
    {
        $this->assertSame([], (new MapDefValidator(0x80000))->errors($this->map()));
    }

    public function test_accepts_a_map_that_ends_exactly_at_the_end_of_the_file(): void
    {
        $map = [...$this->map(), 'address' => 0x80000 - 8 * 16 * 2];

        $this->assertSame([], (new MapDefValidator(0x80000))->errors($map));
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function brokenMaps(): array
    {
        return [
            'not an object' => [fn (array $m): mixed => 'map', 'is not an object'],
            'no name' => [fn (array $m): array => [...$m, 'name' => ' '], 'needs a name'],
            'negative address' => [fn (array $m): array => [...$m, 'address' => -2], 'address is outside the file'],
            'address as text' => [fn (array $m): array => [...$m, 'address' => '4096'], 'address is outside the file'],
            'address past the end' => [fn (array $m): array => [...$m, 'address' => 0x80000], 'address is outside the file'],
            'data past the end' => [fn (array $m): array => [...$m, 'address' => 0x80000 - 10], 'data runs past the end of the file'],
            'unknown dtype' => [fn (array $m): array => [...$m, 'dtype' => 'u64'], 'unknown data type'],
            'zero rows' => [fn (array $m): array => [...$m, 'rows' => 0], 'rows and cols must be'],
            'huge map' => [fn (array $m): array => [...$m, 'rows' => 1000, 'cols' => 1000], 'cells'],
            'infinite factor' => [fn (array $m): array => [...$m, 'factor' => INF], 'factor must be a finite number'],
            'factor as text' => [fn (array $m): array => [...$m, 'factor' => '0.1'], 'factor must be a finite number'],
            'confidence above 1' => [fn (array $m): array => [...$m, 'confidence' => 5], 'confidence must be between 0 and 1'],
            'axis count mismatch' => [fn (array $m): array => [...$m, 'x' => [...$m['x'], 'count' => 12]], 'x axis has 12 points but the map has 16'],
            'axis past the end' => [fn (array $m): array => [...$m, 'x' => [...$m['x'], 'address' => 0x80000 - 4]], 'x axis runs past the end of the file'],
            'axis without data' => [fn (array $m): array => [...$m, 'x' => [...$m['x'], 'address' => null]], 'x axis needs an address or fixed values'],
            'axis values wrong length' => [fn (array $m): array => [...$m, 'y' => [...$m['y'], 'values' => [1, 2]]], 'y axis values must be a list of 8 numbers'],
            'axis values not numbers' => [fn (array $m): array => [...$m, 'y' => [...$m['y'], 'values' => array_fill(0, 8, 'x')]], 'y axis values must be finite numbers'],
        ];
    }

    #[DataProvider('brokenMaps')]
    public function test_rejects_broken_maps(callable $break, string $expected): void
    {
        $errors = (new MapDefValidator(0x80000))->errors($break($this->map()));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($expected, implode(' | ', $errors));
    }
}
