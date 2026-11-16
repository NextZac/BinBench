<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Dtc\Me7DtcLocator;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBin;

class Me7DtcLocatorTest extends TestCase
{
    private const PATHS = 20;

    private const SET1 = 0x132D8;

    private const SET2 = self::SET1 + self::PATHS * 8;

    private const CLASSES = 0x10794;

    private const RECORDS = 0x1B336;

    private const CODEWORD = 0x1846C;

    /**
     * A minimal ME7.5-style fault manager: code-set selector, report routine (called 20 times),
     * enter-fault routine with the class lookup and the class-record pointer, and the tables.
     */
    private function dump(int $codeword = 2): SyntheticBin
    {
        $bin = new SyntheticBin(0x100000);

        // selector: extp #206h,#1 / movb rl4,46Ch / cmpb rl4,#1 / jmpr nz / mov r4,#32D8h / mov 998Eh,r4 / rets / mov r4,#3378h / ...
        $bin->words(0x30000, [0x40D7, 0x0206, 0xF8F3, 0x046C, 0x8149, 0x053D, 0xF4E6, 0x32D8, 0xF4F6, 0x998E, 0x00DB,
            0xF4E6, 0x3378, 0xF4F6, 0x998E, 0x00DB]);
        $bin->ascii(self::CODEWORD, chr($codeword));

        // report routine at 0x40000: mov [-r0],r6 / mov r6,r12 / mov r4,[r0+#2] / calls 84h,1000h
        $bin->words(0x40000, [0x6088, 0x6CF0, 0x40D4, 0x0002, 0x84DA, 0x1000, 0x00DB]);
        for ($i = 0; $i < 20; $i++) {
            $bin->words(0x50000 + $i * 8, [0xFCE6, $i + 1, 0x84DA, 0x0000]);
        }

        // enter-fault routine at 0x41000: movb rl4,[r0+#12h] / movbz r4,rl4 / movb rl6,[r4+#794h] / jmpa cc_Z
        // then mov r7,#3336h / mov r8,#206h / movbz r4,rl6 / r5 = r4 * 14
        $bin->words(0x41000, [0x80F4, 0x0012, 0x84C0, 0xC4F4, 0x0794, 0x20EA, 0x1774,
            0xF7E6, 0x3336, 0xF8E6, 0x0206, 0xC4C0, 0x54F0, 0x355C, 0x5420, 0x155C]);

        $set1 = array_fill(0, self::PATHS * 4, 0);
        $set2 = $set1;
        $classes = array_fill(0, self::PATHS, 0);
        for ($p = 1; $p < self::PATHS - 1; $p++) {
            $digits = hexdec(sprintf('%02d', $p)); // P-codes are stored as hex digits: path 12 -> P1612 = 0x1612
            foreach ([0x1600 + $digits, 0x1700 + $digits, 0x1800 + $digits, 0x1800 + $digits] as $slot => $code) {
                $set1[$p * 4 + $slot] = $code;
                $set2[$p * 4 + $slot] = $code - 0x1000;
            }
            $classes[$p] = $p % 2 ? 3 : 19;
        }
        $bin->words(self::SET1, $set1)->words(self::SET2, $set2);
        $bin->ascii(self::CLASSES, pack('C*', ...$classes));
        $bin->ascii(self::RECORDS + 3 * 14, "\x01")->ascii(self::RECORDS + 19 * 14, "\x00");

        return $bin;
    }

    public function test_finds_both_code_sets_and_the_error_class_from_the_fault_manager_code(): void
    {
        $table = (new Me7DtcLocator)->locate(new Bin($this->dump()->data()));

        $this->assertNotNull($table);
        $this->assertSame(2, $table->codeSize);
        $this->assertFalse($table->bigEndian);
        $this->assertCount(self::PATHS - 2, $table->paths, 'unused paths 0 and N-1 are skipped');

        $path = $table->paths[0];
        $this->assertSame(1, $path->index);
        $this->assertSame(['VAG max', 'VAG min', 'VAG signal', 'VAG plausibility', 'SAE max', 'SAE min', 'SAE signal', 'SAE plausibility'],
            array_column($path->codes, 'slot'));
        $this->assertSame(self::SET1 + 8, $path->codes[0]['address']);
        $this->assertSame(0x1601, $path->codes[0]['code']);
        $this->assertSame(self::SET2 + 8 + 6, $path->codes[7]['address']);
        $this->assertSame([0x1601, 0x1701, 0x1801, 0x0601, 0x0701, 0x0801], $path->pcodes());

        $class = $path->fields[0];
        $this->assertSame('class', $class['key']);
        $this->assertSame(self::CLASSES + 1, $class['address']);
        $this->assertSame(1, $class['size']);
        $this->assertSame(3, $class['value']);
        $this->assertSame(0, $class['offValue']);
        $this->assertStringContainsString('stored, MIL', $class['label']);
        $this->assertStringContainsString('no MIL', $table->paths[1]->fields[0]['label']);
        $this->assertArrayHasKey('class', $table->fieldHelp);
    }

    public function test_reports_which_code_set_the_codeword_selects(): void
    {
        $set2 = (new Me7DtcLocator)->locate(new Bin($this->dump(2)->data()));
        $set1 = (new Me7DtcLocator)->locate(new Bin($this->dump(1)->data()));

        $this->assertStringContainsString('uses set 2', implode("\n", $set2->notes));
        $this->assertStringContainsString('uses set 1', implode("\n", $set1->notes));
    }

    public function test_keeps_the_codes_when_the_class_lookup_is_missing(): void
    {
        $bin = $this->dump()->words(0x41000, array_fill(0, 16, 0xFFFF));

        $table = (new Me7DtcLocator)->locate(new Bin($bin->data()));

        $this->assertNotNull($table);
        $this->assertSame([], $table->paths[0]->fields);
        $this->assertArrayNotHasKey('class', $table->fieldHelp);
    }

    public function test_returns_null_without_a_selector(): void
    {
        $this->assertNull((new Me7DtcLocator)->locate(new Bin(str_repeat("\xFF", 0x100000))));

        $broken = $this->dump()->words(self::SET1, [0x0601]);
        $this->assertNull((new Me7DtcLocator)->locate(new Bin($broken->data())), 'path 0 must be unused');
    }
}
