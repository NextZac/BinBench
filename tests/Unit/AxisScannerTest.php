<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Maps\AxisScanner;
use App\Ecu\Maps\DieselClassifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class AxisScannerTest extends TestCase
{
    public function test_finds_edc15_layout_and_names_drivers_wish(): void
    {
        $rpm = [600, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500];
        $pedal = [0, 1000, 2000, 4000, 6000, 8000, 10000];
        $synthetic = new SyntheticBin(0x10000);
        $address = $synthetic->layoutA(0x4000, $rpm, $pedal, fn (int $r, int $c): int => $c * 800 + $r * 10);

        $candidates = (new AxisScanner(new Bin($synthetic->data())))->scan();
        $this->assertCount(1, $candidates);
        $this->assertSame($address, $candidates[0]['address']);
        $this->assertSame(9, $candidates[0]['rows']);
        $this->assertSame(7, $candidates[0]['cols']);

        $maps = (new DieselClassifier)->classify($candidates);
        $this->assertSame("Driver's wish", $maps[0]->name);
        $this->assertSame(0.01, $maps[0]->factor);
        $this->assertSame('Engine speed', $maps[0]->y->name);
        $this->assertSame('Pedal position', $maps[0]->x->name);
    }

    public function test_finds_big_endian_edc16_layout(): void
    {
        $synthetic = new SyntheticBin(0x10000);
        $rpm = [800, 1200, 1600, 2000, 2500, 3000, 3500, 4000];
        $iq = [0, 500, 1000, 2000, 3000, 4000, 5000, 6000];
        $data = [];
        foreach ($iq as $i => $_) {
            foreach ($rpm as $r => $__) {
                $data[] = 1000 + $i * 120 + $r * 40;
            }
        }
        $synthetic->words(0x2000, [count($rpm), count($iq), ...$rpm, ...$iq, ...$data], be: true);

        $candidates = (new AxisScanner(new Bin($synthetic->data()), be: true))->scan();
        $this->assertCount(1, $candidates);
        $this->assertSame('B', $candidates[0]['layout']);
        $this->assertSame(0x2000 + 2 * (2 + 16), $candidates[0]['address']);
    }

    public function test_ignores_erased_flash_and_random_data(): void
    {
        $this->assertSame([], (new AxisScanner(new Bin(str_repeat("\xFF", 0x4000))))->scan());
    }
}
