<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Families\Me9\Me9LookupScanner;
use App\Ecu\Families\Me9\Me9MapFinder;
use App\Ecu\Maps\MapDef;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic MPC5xx image: start-up code setting r13/r2, a tiny stand-in lookup library
 * (registered through its fingerprints) and call sites passing calibration through r2.
 */
class Me9MapFinderTest extends TestCase
{
    private const SIZE = 0x40000;

    private const CURVE_ROUTINE = 0x2000;

    private const MAP_ROUTINE = 0x2100;

    private const KERNEL = 0x2200;

    private const CURVE_HEADER = 0x28100;

    private const MAP_HEADER = 0x28400;

    /** RAM: r13 = 0x7FFFF0, nmot_w at 0x7FB786, mi at 0x7FB3BE. */
    private const NMOT_W = 0xB796;

    private const MI = 0xB3CE;

    private string $data;

    public function test_names_kfmirl_and_keeps_unidentified_curve(): void
    {
        $bin = $this->image();
        $maps = (new Me9MapFinder($this->fingerprints($bin)))->find($bin);

        $kfmirl = $this->named($maps, 'KFMIRL');
        $this->assertSame(self::MAP_HEADER + 4 + 16 * 2 + 12 * 2, $kfmirl->address);
        $this->assertSame([16, 12, 'u16', true], [$kfmirl->rows, $kfmirl->cols, $kfmirl->dtype, $kfmirl->be]);
        $this->assertSame(0.0234375, $kfmirl->factor);
        $this->assertSame('%', $kfmirl->unit);
        $this->assertSame('Torque', $kfmirl->category);
        $this->assertSame('pattern', $kfmirl->source);
        $this->assertSame('nmot_w', $kfmirl->y->name);
        $this->assertSame(0.25, $kfmirl->y->factor);
        $this->assertSame(self::MAP_HEADER + 4, $kfmirl->y->address);
        $this->assertSame(0.0015259, $kfmirl->x->factor);

        $curve = $this->named($maps, sprintf('KL_%06X', self::CURVE_HEADER + 2 + 16 * 2));
        $this->assertSame([1, 16], [$curve->rows, $curve->cols]);
        $this->assertSame('Other', $curve->category);
        $this->assertSame('nmot_w', $curve->x->name);
        $this->assertLessThan(0.5, $curve->confidence);
    }

    public function test_traces_a_pointer_set_on_a_branch_into_the_call(): void
    {
        $bin = $this->image(alternativeHeader: true);
        $scanner = new Me9LookupScanner($bin, $this->fingerprints($bin));
        $this->assertTrue($scanner->scan());

        $this->assertArrayHasKey(self::CURVE_HEADER + 2 + 32, $scanner->tables);
        $this->assertArrayHasKey(0x28800 + 2 + 32, $scanner->tables);
    }

    public function test_returns_nothing_for_files_without_the_lookup_library(): void
    {
        $this->assertSame([], (new Me9MapFinder)->find(new Bin(str_repeat("\xFF", self::SIZE))));
        $this->assertSame([], (new Me9MapFinder)->find(new Bin(random_bytes(0x20000))));
        $this->assertSame([], (new Me9MapFinder)->find(new Bin('too short')));
    }

    // ---------------------------------------------------------------- image builder

    private function image(bool $alternativeHeader = false): Bin
    {
        $this->data = str_repeat("\xFF", self::SIZE);
        $this->data = substr_replace($this->data, str_repeat("\x00", 0x10000), 0x20000, 0x10000);

        $this->words(0x1000, [
            0x3DA00080, 0x39ADFFF0,         // lis r13,0x80 ; addi r13,r13,-0x10 -> 0x7FFFF0
            0x3C400043, 0x38428000,         // lis r2,0x43  ; addi r2,r2,-0x8000 -> 0x428000 (file 0x28000)
        ]);
        $this->words(self::CURVE_ROUTINE, [0x38E30000, 0x4E800020]);
        $this->words(self::MAP_ROUTINE, [0x7C0802A6, $this->bl(self::MAP_ROUTINE + 4, self::KERNEL), 0x4E800020]);
        $this->words(self::KERNEL, [0x54A7043E, 0x4E800020]);

        $rpm = [2000, 3000, 4000, 5000, 6000, 7000, 8000, 10000, 12000, 14000, 16000, 18000, 20000, 22000, 24000, 28000];
        $this->curve(self::CURVE_HEADER, $rpm, array_fill(0, 16, 5000));
        $mi = [0, 3277, 6554, 9830, 13107, 19661, 26214, 32768, 39321, 45875, 52428, 65535];
        $z = [];
        foreach ($rpm as $r => $_) {
            foreach ($mi as $c => $_) {
                $z[] = 200 + $c * 800 + $r * 5;
            }
        }
        $this->halves(self::MAP_HEADER, [16, 12, ...$rpm, ...$mi, ...$z]);

        $code = [
            0x38620000 | (self::CURVE_HEADER - 0x28000),    // addi r3,r2,curve
            0xA08D0000 | self::NMOT_W,                       // lhz  r4,nmot_w(r13)
            $this->bl(0x3008, self::CURVE_ROUTINE),
            0x38620000 | (self::MAP_HEADER - 0x28000),      // addi r3,r2,map
            0xA08D0000 | self::NMOT_W,                       // lhz  r4,nmot_w(r13)
            0xA0AD0000 | self::MI,                           // lhz  r5,mi(r13)
            $this->bl(0x3018, self::MAP_ROUTINE),
        ];
        if ($alternativeHeader) {
            $this->curve(0x28800, $rpm, array_fill(0, 16, 6000));
            $code = [
                ...$code,
                0x38620000 | 0x800,                         // 0x301C addi r3,r2,other curve
                0x48000008,                                 // 0x3020 b 0x3028
                0x38620000 | (self::CURVE_HEADER - 0x28000), // 0x3024 addi r3,r2,curve
                0xA08D0000 | self::NMOT_W,                   // 0x3028 lhz r4,nmot_w(r13)
                $this->bl(0x302C, self::CURVE_ROUTINE),
            ];
        }
        $this->words(0x3000, [...$code, 0x4E800020]);

        return new Bin($this->data);
    }

    /** @return array<string, array> */
    private function fingerprints(Bin $bin): array
    {
        return [
            Me9LookupScanner::bodyHash($bin, self::CURVE_ROUTINE) => ['kind' => 'KL', 'count' => 'u16', 'x' => 'u16', 'z' => 'u16'],
            Me9LookupScanner::bodyHash($bin, self::MAP_ROUTINE) => ['kind' => 'KF', 'count' => 'u16', 'x' => 'u16', 'y' => 'u16', 'z' => null],
            Me9LookupScanner::bodyHash($bin, self::KERNEL) => ['kind' => 'KER', 'z' => 'u16'],
        ];
    }

    private function curve(int $at, array $axis, array $values): void
    {
        $this->halves($at, [count($axis), ...$axis, ...$values]);
    }

    private function bl(int $from, int $to): int
    {
        return 0x48000001 | (($to - $from) & 0x3FFFFFC);
    }

    /** @param  int[]  $words */
    private function words(int $at, array $words): void
    {
        $this->data = substr_replace($this->data, pack('N*', ...$words), $at, 4 * count($words));
    }

    /** @param  int[]  $values */
    private function halves(int $at, array $values): void
    {
        $this->data = substr_replace($this->data, pack('n*', ...$values), $at, 2 * count($values));
    }

    /** @param  MapDef[]  $maps */
    private function named(array $maps, string $name): MapDef
    {
        foreach ($maps as $m) {
            if ($m->name === $name) {
                return $m;
            }
        }
        $this->fail("$name not found among: ".implode(', ', array_map(fn ($m) => $m->name, $maps)));
    }
}
