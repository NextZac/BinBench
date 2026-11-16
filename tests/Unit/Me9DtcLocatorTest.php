<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Dtc\Me9DtcLocator;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class Me9DtcLocatorTest extends TestCase
{
    private const ROWS = 60;

    private const OBD = 0x1D6000;

    private const MFR = self::OBD + self::ROWS * 8;

    private const CLASSES = 0x1D5000;

    private const SWITCH = 0x1D0010;

    /**
     * A minimal ME9 fault manager: the routine that picks the code table for the tester, the
     * fault-entry class check, and the tables (data addressed through the 0x400000 mirror).
     */
    private function dump(int $switch = 1, bool $withSelect = true): SyntheticBin
    {
        $bin = new SyntheticBin(0x200000);
        if ($withSelect) {
            // lis r12,5Dh / lbz r12,10h(r12) / cmpwi r12,1 / bne / lis r3,5Dh / addi r3,r3,6000h / b / lis r3,5Dh / addi r3,r3,61E0h / stw r3,-6AE0h(r13) / blr
            $bin->words(0xC5FB4, [0x3D80, 0x005D, 0x898C, 0x0010, 0x2C0C, 0x0001, 0x4082, 0x0010, 0x3C60, 0x005D, 0x3863, 0x6000,
                0x4800, 0x000C, 0x3C60, 0x005D, 0x3863, 0x61E0, 0x906D, 0x9520, 0x4E80, 0x0020], true);
        }
        // lis r31,5Dh / addi r31,r31,5000h / slwi r11,r3,2 / add / clrlwi / lbzx / cmpwi r10,0 / beq / clrlwi / lbzx / cmpwi r12,27h
        $bin->words(0x102190, [0x3FE0, 0x005D, 0x3BFF, 0x5000, 0x546B, 0x103A, 0x7EBF, 0x5A14, 0x57CA, 0x063E, 0x7D55, 0x50AE,
            0x2C0A, 0x0000, 0x4182, 0x0014, 0x57CC, 0x063E, 0x7D95, 0x60AE, 0x2C0C, 0x0027], true);
        $bin->ascii(self::SWITCH, chr($switch));

        $obd = array_fill(0, self::ROWS * 4, 0);
        $mfr = $obd;
        $classes = array_fill(0, self::ROWS * 4, 0);
        for ($p = 1; $p <= 30; $p++) {
            foreach ([0x0338, 0x0337, 0x0335, 0x0336] as $slot => $code) {
                $obd[$p * 4 + $slot] = $code + 0x100 * ($p % 4);
                $mfr[$p * 4 + $slot] = 0x5000 + $p * 16 + $slot;
                $classes[$p * 4 + $slot] = $p === 7 && $slot === 2 ? 0 : 0x1E;
            }
        }
        $bin->words(self::OBD, $obd, true)->words(self::MFR, $mfr, true);
        $bin->ascii(self::CLASSES, pack('C*', ...$classes));

        return $bin;
    }

    public function test_finds_code_and_class_tables_from_the_code(): void
    {
        $table = (new Me9DtcLocator)->locate(new Bin($this->dump()->data()));

        $this->assertNotNull($table);
        $this->assertTrue($table->bigEndian);
        $this->assertSame(2, $table->codeSize);
        $this->assertCount(30, $table->paths);

        $path = $table->paths[0];
        $this->assertSame(1, $path->index);
        $this->assertSame(['slot' => 'obd-max', 'address' => self::OBD + 8, 'code' => 0x0438], $path->codes[0]);
        $this->assertSame(['slot' => 'obd-plausibility', 'address' => self::OBD + 14, 'code' => 0x0436], $path->codes[3]);
        $this->assertCount(4, $path->codes);
    }

    public function test_class_per_fault_type_switches_off_at_zero(): void
    {
        $paths = (new Me9DtcLocator)->locate(new Bin($this->dump()->data()))->paths;

        $field = $paths[6]->fields[2];
        $this->assertSame(7, $paths[6]->index);
        $this->assertSame(['key' => 'class-signal', 'label' => 'Error class signal', 'address' => self::CLASSES + 7 * 4 + 2, 'size' => 1, 'value' => 0, 'offValue' => 0], $field);
        $this->assertSame(0x1E, $paths[6]->fields[0]['value']);
    }

    public function test_adds_manufacturer_codes_when_the_tester_reads_that_table(): void
    {
        $path = (new Me9DtcLocator)->locate(new Bin($this->dump(switch: 0)->data()))->paths[0];

        $this->assertCount(8, $path->codes);
        $this->assertSame(['slot' => 'mfr-max', 'address' => self::MFR + 8, 'code' => 0x5010], $path->codes[4]);
    }

    public function test_returns_null_without_the_table_select_routine(): void
    {
        $this->assertNull((new Me9DtcLocator)->locate(new Bin($this->dump(withSelect: false)->data())));
    }
}
