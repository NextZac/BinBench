<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Patches\Dde4CruiseButtonsMultimap;
use App\Ecu\Patches\Dde4CruiseSwitchMultimap;
use App\Ecu\Patches\PatchService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticDde4;

class Dde4MultimapTest extends TestCase
{
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

    public function test_both_multimaps_are_offered_on_a_stock_read(): void
    {
        $patches = (new PatchService)->describe(new Bin(SyntheticDde4::build()->data()), 'edc15');

        $this->assertSame(['dde4-multimap-2', 'dde4-multimap-3'], array_column($patches, 'id'));
        $this->assertSame(['available', 'available'], array_column($patches, 'status'));
        $this->assertSame([], (new PatchService)->describe(new Bin(SyntheticDde4::build()->data()), 'me7'));
    }

    public function test_three_map_install_hooks_the_table_loads_and_copies_the_tables(): void
    {
        $stock = new Bin(SyntheticDde4::build()->data());
        $patch = new Dde4CruiseButtonsMultimap;

        $patched = $this->apply($stock, $patch->install($stock));

        $this->assertSame('installed', $patch->status($patched)['status']);
        $this->assertSame('da0bf2e0', bin2hex($patched->slice(0x29238, 4)), 'launch torque limiter load calls its stub');
        $this->assertSame('cc00cc00', bin2hex($patched->slice(0x252F8, 4)), 'cruise resume timestamp write is NOPed');
        $this->assertSame('f68e5cd1f68eb4d1db00', bin2hex($patched->slice(0x3E000, 10)), 'init stub keeps the original instruction');
        // Map 2 and Map 3 copies start equal to the file's own tables
        $this->assertSame($stock->slice(0x73F4E, 0xB0), $patched->slice(0x70A00, 0xB0));
        $this->assertSame($stock->slice(0x7809E, 0x200), $patched->slice(0x7BD00, 0x200));
        $this->assertSame($stock->slice(0x7A8BA, 0x50), $patched->slice(0x70EF0, 0x50));
    }

    public function test_map_three_smoke_table_takes_the_lower_stock_limit_on_its_own_axes(): void
    {
        $stock = new Bin(SyntheticDde4::build(staticSmoke: 4000, dynamicSmoke: 3000)->data());
        $patched = $this->apply($stock, (new Dde4CruiseButtonsMultimap)->install($stock));

        $words = array_values(unpack('v*', $patched->slice(0x7BF00, 0xA8)));

        $this->assertSame([0xC016, 8, 750, 1000, 1300, 1600, 2200, 2800, 4000, 4900], array_slice($words, 0, 10));
        $this->assertSame([0xC20C, 8, 2500, 3500, 4500, 5500, 6500, 7500, 8500, 10000], array_slice($words, 10, 10));
        $this->assertSame(array_fill(0, 64, 3000), array_slice($words, 20));
    }

    public function test_uninstall_restores_the_stock_bytes(): void
    {
        $stock = new Bin(SyntheticDde4::build()->data());
        foreach ([new Dde4CruiseSwitchMultimap, new Dde4CruiseButtonsMultimap] as $patch) {
            $patched = $this->apply($stock, $patch->install($stock));

            $this->assertSame($stock->data, $this->apply($patched, $patch->uninstall($patched))->data, $patch->id());
        }
    }

    public function test_an_installed_multimap_blocks_the_other_one(): void
    {
        $stock = new Bin(SyntheticDde4::build()->data());
        $twoMaps = $this->apply($stock, (new Dde4CruiseSwitchMultimap)->install($stock));

        $status = (new Dde4CruiseButtonsMultimap)->status($twoMaps);

        $this->assertSame('unavailable', $status['status']);
        $this->assertStringContainsString('Another code patch is installed', $status['reason']);
    }

    public function test_is_not_offered_on_other_software_partial_reads_or_changed_code(): void
    {
        $patch = new Dde4CruiseSwitchMultimap;
        $stock = SyntheticDde4::build()->data();

        $otherSoftware = $patch->status(new Bin(SyntheticDde4::build('1037366536')->data()));
        $this->assertSame('unavailable', $otherSoftware['status']);
        $this->assertStringContainsString('this file is 1037366536', $otherSoftware['reason']);

        $calibrationOnly = $patch->status(new Bin(substr($stock, 0x70000, 0xC000)));
        $this->assertStringContainsString('full 512 KB read', $calibrationOnly['reason']);

        $changedHook = $patch->status((new Bin($stock))->withPatch(0x27CC0, "\xE6\xFC\x00\x00"));
        $this->assertStringContainsString('0x27CC0 is not the stock instruction', $changedHook['reason']);

        $usedSpace = $patch->status((new Bin($stock))->withPatch(0x70A10, "\x00"));
        $this->assertStringContainsString('0x70A00 is already in use', $usedSpace['reason']);
    }

    public function test_describes_the_switched_tables_for_both_maps(): void
    {
        $stock = new Bin(SyntheticDde4::build()->data());
        $maps = (new Dde4CruiseButtonsMultimap)->maps($stock);
        $byName = array_column(array_map(fn ($map): array => ['name' => $map->name, 'map' => $map], $maps), 'map', 'name');

        $this->assertCount(14, $maps);
        $launch = $byName['Map 2 · Launch / standstill torque limiter (mrwADB_KL)'];
        $this->assertSame(0x70AC0 + 0x2A, $launch->address);
        $this->assertSame(0x70AC0 + 0x04, $launch->x->address);
        $timing = $byName['Map 2 · Main injection timing earliest (zuwABHmxKF)'];
        $this->assertSame(0x70DA0, $timing->address);
        $this->assertSame(0x74F3C, $timing->y->address, 'timing copies keep the shared rpm axis');
        $this->assertSame('s16', $timing->dtype);
    }
}
