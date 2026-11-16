<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Checksum\Edc16cBmwChecksum;
use App\Ecu\Families\Edc16Family;
use App\Ecu\Maps\MapDef;
use App\Ecu\Patches\Dde5CruiseSwitchMultimap;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\SyntheticDde5;

/**
 * BMW EDC16C (DDE5) 1.5 MB full read: split address space (external flash at CPU 0x800000 = file
 * 0x70000), FADECAFE block headers away from their block start, tables addressed by lis/addi pairs.
 */
class Edc16cBmwTest extends TestCase
{
    private const SIZE = SyntheticDde5::SIZE;

    private const RAIL = SyntheticDde5::RAIL;

    private function file(int $cpu): int
    {
        return SyntheticDde5::file($cpu);
    }

    private function dump(string $software = '1037361878', bool $multimapSites = false): Bin
    {
        return new Bin(SyntheticDde5::build($software, $multimapSites));
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

    /**
     * @param  array<int, array{address: int, bytes: string}>  $writes
     */
    private function apply(Bin $bin, array $writes): Bin
    {
        foreach ($writes as $write) {
            $bin = $bin->withPatch($write['address'], $write['bytes']);
        }

        return $bin;
    }

    public function test_multimap_switches_each_table_load_through_a_stub(): void
    {
        $stock = $this->dump(multimapSites: true);
        $patch = new Dde5CruiseSwitchMultimap;
        $this->assertSame('available', $patch->status($stock)['status']);

        $patched = $this->apply($stock, $patch->install($stock));

        $this->assertSame('installed', $patch->status($patched)['status']);
        // the launch-curve addi at 0x85624C now branches to the first stub at 0x875000
        $this->assertSame(0x4801EDB4, $patched->u32($this->file(0x85624C), true));
        $this->assertSame([0x888D8A34, 0x5484177A, 0x3C840087, 0x80845100, 0x4BFE1240], array_values(unpack('N5', $patched->slice($this->file(0x875000), 20))));
        $this->assertSame([0x8E8236, 0x875200], array_values(unpack('N2', $patched->slice($this->file(0x875100), 8))));
        $this->assertSame($stock->slice($this->file(0x8E8236), 86), $patched->slice($this->file(0x875200), 86), 'Map 2 starts as a copy');
        $this->assertTrue((new Edc16cBmwChecksum)->fix($patched) instanceof Bin);
    }

    public function test_multimap_uninstall_restores_the_stock_read_and_lists_both_maps(): void
    {
        $stock = $this->dump(multimapSites: true);
        $patch = new Dde5CruiseSwitchMultimap;
        $patched = $this->apply($stock, $patch->install($stock));

        $this->assertSame($stock->data, $this->apply($patched, $patch->uninstall($patched))->data);
        $names = array_map(fn (MapDef $map): string => $map->name, $patch->maps($patched));
        $this->assertCount(24, $names);
        $this->assertContains('Map 2 · Boost pressure limit (atm. pressure)', $names);
    }

    public function test_multimap_is_not_offered_on_changed_code_or_other_software(): void
    {
        $patch = new Dde5CruiseSwitchMultimap;

        $this->assertStringContainsString('this file is 1037399999', $patch->status($this->dump('1037399999', true))['reason']);
        $changed = $this->dump(multimapSites: true)->withPatch($this->file(0x855470), "\x60\x00\x00\x00");
        $this->assertStringContainsString('0x855470 is not the stock table load', $patch->status($changed)['reason']);
        $used = $this->dump(multimapSites: true)->withPatch($this->file(0x876000), "\x00");
        $this->assertStringContainsString('already in use', $patch->status($used)['reason']);
    }
}
