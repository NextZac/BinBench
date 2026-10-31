<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Dtc\Edc15DtcLocator;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class Edc15DtcLocatorTest extends TestCase
{
    private const PATHS = 12;

    private const LIST = 0x15FC8;

    private const POINTERS = 0x71590;

    private const BLOCKS = 0x72700;

    /**
     * A minimal DDE4 layout: DPP0 = 3Ch, the start-up loop that copies the path pointers to RAM,
     * the path list (DPP2 = 25h), the pointer table and one 0x22-byte block per path.
     */
    private function dump(bool $withLoop = true): SyntheticBin
    {
        $bin = new SyntheticBin(0x80000, "\xC3");
        $bin->ascii(0x100BC, "\xE6\x00\x3C\x00");
        if ($withLoop) {
            // scxt DPP2,#25h / mov r0,#9FC8h / mov r1,#0CAA6h / mov r2,r0 / add r2,#len / cmp r0,r2 / jmpr nc / mov r3,[r0+] / mov [r1+],[r3]
            $bin->ascii(0x16B48, "\xC6\x02\x25\x00\xE6\xF0\xC8\x9F\xE6\xF1\xA6\xCA\xF0\x20\x06\xF2".pack('v', self::PATHS * 2)."\x40\x02\x9D\x03\x98\x30\xD8\x13");
        }

        for ($i = 0; $i < self::PATHS; $i++) {
            $entry = self::POINTERS + 4 * $i;
            $block = self::BLOCKS + 0x40 * $i;
            $bin->words(self::LIST + 2 * $i, [$entry - 0x70000]);
            // path 5 is not present in this dataset: its pointer has bit 0 set
            $bin->words($entry, [($block - 0x70000) | ($i === 5 ? 1 : 0)]);
            $code = $i === 2 ? 0x0335 : 0x1E00 + $i;
            $bin->words($block, [$code])
                ->ascii($block + 2, pack('C4', $i === 9 ? 2 : 0, $i === 7 ? 0 : 0x41, 1, 0x28))
                ->words($block + 6, [0, $i === 4 ? 0x0420 : 0, 0, 0, 0, 0, 0, 0, 0]);
        }

        return $bin;
    }

    public function test_follows_the_path_list_to_each_fault_path_block(): void
    {
        $table = (new Edc15DtcLocator)->locate(new Bin($this->dump()->data()));

        $this->assertNotNull($table);
        $this->assertFalse($table->bigEndian);
        $this->assertSame(2, $table->codeSize);
        $this->assertCount(self::PATHS - 1, $table->paths);
        $this->assertNotContains(5, array_map(fn ($path) => $path->index, $table->paths));

        $crank = $table->paths[2];
        $this->assertSame(2, $crank->index);
        $this->assertSame('Crankshaft speed sensor (DZG)', $crank->name);
        $this->assertSame(['slot' => 'spc', 'address' => self::BLOCKS + 0x80, 'code' => 0x0335], $crank->codes[0]);
        $this->assertSame([0x0335], $crank->pcodes());
    }

    public function test_reports_debounce_mode_and_priority_with_zero_as_off(): void
    {
        $paths = (new Edc15DtcLocator)->locate(new Bin($this->dump()->data()))->paths;
        $byIndex = array_column(array_map(fn ($path) => ['index' => $path->index, 'path' => $path], $paths), 'path', 'index');

        $ent = $byIndex[0]->fields[0];
        $this->assertSame(['key' => 'ent', 'label' => 'Debounce mode', 'address' => self::BLOCKS + 3, 'size' => 1, 'value' => 0x41, 'offValue' => 0], $ent);
        $this->assertSame(0, $byIndex[7]->fields[0]['value']);
        $this->assertSame('pri', $byIndex[9]->fields[1]['key']);
        $this->assertSame(2, $byIndex[9]->fields[1]['value']);
    }

    public function test_lists_carb_code_slots_and_names_only_matching_paths(): void
    {
        $paths = (new Edc15DtcLocator)->locate(new Bin($this->dump()->data()))->paths;

        $this->assertCount(9, $paths[4]->codes);
        $this->assertSame(['slot' => 'carb0', 'address' => self::BLOCKS + 0x100 + 8, 'code' => 0x0420], $paths[4]->codes[1]);
        $this->assertSame([0x1E04, 0x0420], $paths[4]->pcodes());
        // path 1 is the ECU processor path on the known software, but the code does not match here
        $this->assertNull($paths[1]->name);
    }

    public function test_returns_null_without_the_pointer_copy_loop(): void
    {
        $this->assertNull((new Edc15DtcLocator)->locate(new Bin($this->dump(withLoop: false)->data())));
    }

    public function test_returns_null_for_a_calibration_only_read(): void
    {
        $this->assertNull((new Edc15DtcLocator)->locate(new Bin(substr($this->dump()->data(), 0x70000, 0xC000))));
    }
}
