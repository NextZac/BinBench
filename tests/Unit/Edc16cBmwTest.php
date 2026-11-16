<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Checksum\Edc16cBmwChecksum;
use App\Ecu\Families\Edc16Family;
use App\Ecu\Maps\MapDef;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SyntheticBin;

/**
 * BMW EDC16C (DDE5) 1.5 MB full read: split address space (external flash at CPU 0x800000 = file
 * 0x70000), FADECAFE block headers away from their block start, tables addressed by lis/addi pairs.
 */
class Edc16cBmwTest extends TestCase
{
    private const SIZE = 0x170000;

    private const MAGIC = "\xFA\xDE\xCA\xFE\xCA\xFE\xAF\xFE";

    /** [header marker file offset, CPU start, CPU end inclusive] as in SW 1037361878. */
    private const BLOCKS = [
        [0x75D8, 0x0, 0x7FBF],
        [0x813C, 0x8000, 0x6FFFF],
        [0xB003C, 0x840000, 0x87FFFB],
        [0x150080, 0x8DFF80, 0x8FFBFB],
    ];

    /** Rail pressure target of SW 1037361878 (CPU 0x8FC9C6). */
    private const RAIL = 0x16C9C6;

    private function file(int $cpu): int
    {
        return $cpu < 0x800000 ? $cpu : $cpu - 0x790000;
    }

    private function lisAddi(int $register, int $address): array
    {
        return [
            (15 << 26) | ($register << 21) | ((($address + 0x8000) >> 16) & 0xFFFF),
            (14 << 26) | ($register << 21) | ($register << 16) | ($address & 0xFFFF),
        ];
    }

    /** 2D table [nx][ny][rpm][quantity][data] at a file offset. */
    private function table(SyntheticBin $bin, int $at, int $n, int $base): void
    {
        $rpm = array_map(fn (int $i): int => 500 + 300 * $i, range(0, $n - 1));
        $quantity = array_map(fn (int $i): int => 500 * $i, range(0, $n - 1));
        $data = [];
        foreach (range(0, $n - 1) as $r) {
            foreach (range(0, $n - 1) as $q) {
                $data[] = $base + 300 * $r + 200 * $q;
            }
        }
        $bin->words($at, [$n, $n, ...$rpm, ...$quantity, ...$data], true);
    }

    private function closeBlocks(string $data): string
    {
        foreach (self::BLOCKS as [, $start, $end]) {
            $s = $this->file($start);
            $e = $this->file($end);
            $data = substr_replace($data, "\0\0\0\0", $e - 3, 4);
            $sum = array_sum(unpack('N*', substr($data, $s, $e + 1 - $s))) & 0xFFFFFFFF;
            $data = substr_replace($data, pack('N', (0xD01FE500 - $sum) & 0xFFFFFFFF), $e - 3, 4);
        }

        return $data;
    }

    private function dump(string $software = '1037361878'): Bin
    {
        $bin = new SyntheticBin(self::SIZE);
        $bin->ascii(0x8C5A6, 'BOSCH EDC16C/C (BMW) MPC555/RevG-K3')
            ->ascii(0x76000, '0281011414')
            ->ascii(0x150054, $software.'388AC62A');
        foreach (self::BLOCKS as [$marker, $start, $end]) {
            $bin->words($marker - 8, [$start >> 16, $start & 0xFFFF, $end >> 16, $end & 0xFFFF], true)->ascii($marker, self::MAGIC);
        }
        // code in the external application loads the rail table and a second table; a third is never referenced
        $code = [...$this->lisAddi(5, 0x8FC9C6), 0x48000001, ...$this->lisAddi(5, 0x8F1000), 0x48000001];
        $bin->ascii(0xB1000, pack('N*', ...$code));
        $this->table($bin, self::RAIL, 16, 3000);
        $this->table($bin, $this->file(0x8F1000), 8, 1000);
        $this->table($bin, $this->file(0x8F2000), 8, 1000);

        return new Bin($this->closeBlocks($bin->data()));
    }

    public function test_checks_every_block_from_its_header(): void
    {
        $module = new Edc16cBmwChecksum;
        $bin = $this->dump();

        $this->assertTrue($module->supports($bin));
        $blocks = $module->check($bin);
        $this->assertSame(['Startup 0x000000-0x007FBF', 'Internal application 0x008000-0x06FFFF', 'External application 0x840000-0x87FFFB', 'Data 0x8DFF80-0x8FFBFB'], array_column($blocks, 'name'));
        $this->assertSame([true, true, true, true], array_column($blocks, 'ok'));
    }

    public function test_fix_rewrites_only_the_compensation_words_of_changed_blocks(): void
    {
        $module = new Edc16cBmwChecksum;
        $stock = $this->dump();
        $edited = $stock->withPatch(self::RAIL + 0x100, "\x12\x34")->withPatch(0xB2000, "\x60\x00\x00\x00");
        $this->assertSame([true, true, false, false], array_column($module->check($edited), 'ok'));

        $fixed = $module->fix($edited);

        $this->assertSame([true, true, true, true], array_column($module->check($fixed), 'ok'));
        $changed = [];
        for ($i = 0; $i < self::SIZE; $i++) {
            if ($fixed->data[$i] !== $edited->data[$i]) {
                $changed[] = $i;
            }
        }
        $this->assertEmpty(array_diff($changed, [...range(0xEFFF8, 0xEFFFB), ...range(0x16FBF8, 0x16FBFB)]));
    }

    public function test_never_corrects_the_startup_block(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('startup block');

        (new Edc16cBmwChecksum)->fix($this->dump()->withPatch(0x100, "\x01"));
    }

    public function test_identifies_the_bmw_layout(): void
    {
        $ident = (new Edc16Family)->detect($this->dump());

        $this->assertSame('EDC16C (BMW DDE5)', $ident['variant']);
        $this->assertSame('1037361878', $ident['sw']);
        $this->assertInstanceOf(Edc16cBmwChecksum::class, (new Edc16Family)->checksum($this->dump()));
    }

    public function test_finds_only_referenced_tables_and_names_known_ones(): void
    {
        $bin = $this->dump();
        $family = new Edc16Family;

        $maps = $family->findMaps($bin, $family->detect($bin));
        $byAddress = array_column(array_map(fn (MapDef $map): array => ['address' => $map->address, 'map' => $map], $maps), 'map', 'address');

        $rail = $byAddress[self::RAIL + 4 + 2 * 32] ?? null;
        $this->assertNotNull($rail);
        $this->assertSame('Rail pressure target', $rail->name);
        $this->assertSame('bar', $rail->unit);
        $this->assertSame([16, 16], [$rail->rows, $rail->cols]);
        $this->assertSame('Engine speed', $rail->y->name);
        $this->assertSame('catalog', $rail->source);

        $other = $byAddress[$this->file(0x8F1000) + 4 + 2 * 16] ?? null;
        $this->assertNotNull($other, 'a referenced table is found');
        $this->assertNotSame('catalog', $other->source);
        $this->assertArrayNotHasKey($this->file(0x8F2000) + 4 + 2 * 16, $byAddress, 'an unreferenced table is ignored');
    }

    public function test_other_software_gets_no_names_but_still_referenced_maps(): void
    {
        $bin = $this->dump('1037399999');
        $family = new Edc16Family;

        $maps = $family->findMaps($bin, $family->detect($bin));

        $this->assertCount(2, $maps);
        $this->assertNotContains('catalog', array_map(fn (MapDef $map): string => $map->source, $maps));
    }
}
