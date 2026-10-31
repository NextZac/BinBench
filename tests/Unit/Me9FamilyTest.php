<?php

namespace Tests\Unit;

use App\Ecu\Analyzer;
use App\Ecu\Bin;
use App\Ecu\Families\Me9Family;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class Me9FamilyTest extends TestCase
{
    private function me9Dump(): SyntheticBin
    {
        return (new SyntheticBin(0x200000))
            ->ascii(0x2BCC, 'ME90400261209048SERIESXPC1E03B')
            ->ascii(0x80308, 'P040D---1037393284123456789ABC')
            ->ascii(0x1C3480, '54/1/ME90C/2/I525PFO3//PS40C700/PS40C700/121007190128/');
    }

    public function test_identifies_a_ford_volvo_me9_read(): void
    {
        $result = (new Analyzer)->identify(new Bin($this->me9Dump()->data()));

        $this->assertSame('me9', $result['family']->id());
        $ident = $result['ident'];
        $this->assertSame('Bosch ME9.0', $ident['family']);
        $this->assertSame('ME90C', $ident['variant']);
        $this->assertSame('0261209048', $ident['hw']);
        $this->assertSame('1037393284', $ident['sw']);
        $this->assertSame('PS40C700', $ident['swVersion']);
        $this->assertSame('2.5L R5', $ident['engine']);
        $this->assertSame('be', $ident['endian']);
    }

    public function test_ignores_files_without_an_me9_ident(): void
    {
        $bin = (new SyntheticBin(0x200000))->ascii(0x1000, '42/1/ME7.5/381/4518.SA//22k/')->data();

        $this->assertNull((new Me9Family)->detect(new Bin($bin)));
    }

    public function test_falls_back_to_structure_scan_in_the_calibration_segment(): void
    {
        $dump = $this->me9Dump()
            ->words(0x1C3200, [0x5A5A, 0x5A5A, 0xCCCC, 0xCCCC], be: true)
            ->words(0x1C321C, [0x001D, 0xAED8], be: true);
        $rpm = [800, 1200, 1600, 2000, 2500, 3000, 3500, 4000];
        $load = [10, 20, 40, 60, 80, 100];
        $data = [];
        foreach ($load as $l) {
            foreach ($rpm as $r) {
                $data[] = 1000 + $l * 10 + $r / 10;
            }
        }
        $dump->words(0x1C8000, [count($rpm), count($load), ...$rpm, ...$load, ...$data], be: true);

        $family = new Me9Family;
        $bin = new Bin($dump->data());
        $maps = $family->findMaps($bin, $family->detect($bin));
        $addresses = array_map(fn ($m) => $m->address, $maps);

        $this->assertContains(0x1C8000 + 2 * (2 + count($rpm) + count($load)), $addresses);
    }
}
