<?php

namespace Tests\Unit;

use App\Ecu\Bin;
use App\Ecu\Checksum\Me9Checksum;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Me9ChecksumTest extends TestCase
{
    /** [segment start, segment end, header, calibration?] */
    private const SEGMENTS = [
        [0x020000, 0x07FFFF, 0x020030, false],
        [0x080000, 0x09FFFF, 0x080100, false],
        [0x0A0000, 0x1BFFFF, 0x0A0000, false],
        [0x1C2000, 0x1DFFFF, 0x1C3200, true],
        [0x1E0000, 0x1FFFFF, 0x1E4000, false],
    ];

    /** [descriptor offset, start address, end address]; calibration uses the 0x5C0000 mirror. */
    private const DESCRIPTORS = [
        [0x001FF0, 0x000000, 0x001FFF],
        [0x01FFC0, 0x010000, 0x013FFF],
        [0x01FFD0, 0x014000, 0x017FFF],
        [0x01FFE0, 0x018000, 0x01BFFF],
        [0x0A0210, 0x0A0400, 0x0A7FFF],
        [0x0A0220, 0x0A8000, 0x0AFFFF],
        [0x0A0230, 0x0B0000, 0x0B7FFF],
        [0x1CA8B0, 0x5C2000, 0x5C32FF],
        [0x1CA8C0, 0x5C8000, 0x5CFFFF],
        [0x1CA8D0, 0x5D0000, 0x5D7FFF],
    ];

    private Me9Checksum $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Me9Checksum;
    }

    public function test_consistent_image_passes_every_block(): void
    {
        $bin = $this->image();

        $this->assertTrue($this->module->supports($bin));
        $blocks = $this->module->check($bin);
        $this->assertCount(count(self::DESCRIPTORS) + count(self::SEGMENTS), $blocks);
        $this->assertSame([], $this->failing($blocks));
    }

    public function test_calibration_edit_flags_its_word_sum_and_crc_and_fix_repairs_only_those(): void
    {
        $edited = $this->image()->withPatch(0x1C8A10, str_repeat("\x12\x34", 0x100));

        $failing = $this->failing($this->module->check($edited));
        $this->assertCount(2, $failing);
        $this->assertStringContainsString('0x1C8000-0x1CFFFF', $failing[0]);
        $this->assertStringContainsString('CRC32 Calibration', $failing[1]);

        $fixed = $this->module->fix($edited);
        $this->assertSame([], $this->failing($this->module->check($fixed)));
        $allowed = [...range(0x1C3238, 0x1C323F), ...range(0x1CA8C8, 0x1CA8CF)];
        $this->assertSame([], array_diff($this->changedOffsets($edited, $fixed), $allowed));
    }

    public function test_calibration_only_file_is_checked_and_fixed(): void
    {
        $edited = $this->image()->withPatch(0x1D0010, "\x00\x01");
        $cal = new Bin(substr($edited->data, 0x1C0000, 0x20000));

        $this->assertTrue($this->module->supports($cal));
        $this->assertCount(4, $this->module->check($cal));
        $this->assertCount(2, $this->failing($this->module->check($cal)));

        $fixed = $this->module->fix($cal);
        $this->assertSame([], $this->failing($this->module->check($fixed)));
        $this->assertSame(substr($this->module->fix($edited)->data, 0x1C0000, 0x20000), $fixed->data);
    }

    public function test_rejects_images_without_the_segment_headers(): void
    {
        $blank = new Bin(str_repeat("\xFF", 0x200000));

        $this->assertFalse($this->module->supports($blank));
        $this->assertFalse($this->module->supports(new Bin(str_repeat("\xFF", 0x80000))));
        $this->expectException(RuntimeException::class);
        $this->module->fix($blank);
    }

    /** Synthetic 2 MB flash with valid headers, word sums and segment CRCs. */
    private function image(): Bin
    {
        $d = '';
        for ($i = 0; strlen($d) < 0x200000; $i++) {
            $d .= md5((string) $i, true);
        }

        foreach (self::SEGMENTS as [, , $hdr, $cal]) {
            $block = str_repeat("\xFF", 0x200);
            $block = substr_replace($block, pack('N', 0x5A5A5A5A).($cal ? "\xCC\xCC\xCC\xCC" : "\x33\x33\x33\x33")
                .pack('N3', $hdr + 0x80, $hdr + 0x40, $hdr + 0xC0), 0, 20);
            $block = substr_replace($block, pack('N2', 0, 0xFFFFFFFF), 0x38, 8);
            $block = substr_replace($block, pack('N', 0xAFFE0815).str_repeat("\0", 0x3C), 0x80, 0x40);
            $d = substr_replace($d, $block, $hdr, 0x200);
        }
        // Placeholder {0, ~0} pairs: any {x, ~x} adds the same 0x1FFFE to a containing word sum.
        foreach (self::DESCRIPTORS as [$at, $from, $to]) {
            $d = substr_replace($d, pack('N4', $from, $to, 0, 0xFFFFFFFF), $at, 16);
        }

        foreach (self::DESCRIPTORS as [$at, $from, $to]) {
            $mirror = $from >= 0x5C0000 ? 0x400000 : 0;
            $sum = array_sum(unpack('n*', substr($d, $from - $mirror, $to - $from + 1))) & 0xFFFFFFFF;
            $d = substr_replace($d, pack('N4', $from, $to, $sum, ~$sum & 0xFFFFFFFF), $at, 16);
        }

        foreach (self::SEGMENTS as [$start, $end, $hdr]) {
            $crc = crc32(substr($d, $start, $hdr + 0x38 - $start).str_repeat("\xFF", 8)
                .substr($d, $hdr + 0x40, 0x40).str_repeat("\xFF", 0x40)
                .substr($d, $hdr + 0xC0, $end + 1 - ($hdr + 0xC0)));
            $d = substr_replace($d, pack('N2', $crc, ~$crc & 0xFFFFFFFF), $hdr + 0x38, 8);
        }

        return new Bin($d);
    }

    /**
     * @param  array<int, array{name:string,ok:bool}>  $blocks
     * @return string[]
     */
    private function failing(array $blocks): array
    {
        return array_values(array_map(fn (array $b): string => $b['name'], array_filter($blocks, fn (array $b): bool => ! $b['ok'])));
    }

    /** @return int[] */
    private function changedOffsets(Bin $a, Bin $b): array
    {
        $out = [];
        for ($i = 0; $i < $a->size; $i++) {
            if ($a->data[$i] !== $b->data[$i]) {
                $out[] = $i;
            }
        }

        return $out;
    }
}
