<?php

namespace Tests\Support;

/**
 * A BMW EDC16C (DDE5) 1.5 MB full read shaped like SW 1037361878: split address space (external
 * flash at CPU 0x800000 = file 0x70000), FADECAFE block headers away from their block start with
 * closed sums, and tables loaded by "lis/addi" pairs in the external application.
 */
class SyntheticDde5
{
    public const SIZE = 0x170000;

    /** Rail pressure target of SW 1037361878 (CPU 0x8FC9C6), as a file offset. */
    public const RAIL = 0x16C9C6;

    private const MAGIC = "\xFA\xDE\xCA\xFE\xCA\xFE\xAF\xFE";

    /** [header marker file offset, CPU start, CPU end inclusive] as in SW 1037361878. */
    private const BLOCKS = [
        [0x75D8, 0x0, 0x7FBF],
        [0x813C, 0x8000, 0x6FFFF],
        [0xB003C, 0x840000, 0x87FFFB],
        [0x150080, 0x8DFF80, 0x8FFBFB],
    ];

    /** [table, count, lis site, register] of the 12 table loads the DDE5 multimap switches. */
    private const MULTIMAP_SITES = [
        [0x8E8236, 21, 0x856248, 4], [0x8E82E2, 21, 0x856260, 4], [0x8E8362, 21, 0x8562AC, 4], [0x8E8338, 10, 0x8562C4, 4],
        [0x8E984C, 16, 0x85546C, 5], [0x8E916C, 16, 0x855488, 5], [0x8E9A90, 16, 0x8554A8, 5], [0x8E93B0, 16, 0x8554C4, 5],
        [0x8F9196, 16, 0x86EF40, 5], [0x8F8D0E, 16, 0x86EF60, 5], [0x8F8F52, 16, 0x86EF7C, 5], [0x8F93DA, 13, 0x86EF98, 5],
    ];

    /**
     * The rail table and one more table are loaded by code; a third table is never referenced.
     * With $multimapSites, the 12 multimap table loads and their tables are added too.
     */
    public static function build(string $software = '1037361878', bool $multimapSites = false): string
    {
        $bin = new SyntheticBin(self::SIZE);
        $bin->ascii(0x8C5A6, 'BOSCH EDC16C/C (BMW) MPC555/RevG-K3')
            ->ascii(0x76000, '0281011414')
            ->ascii(0x150054, $software.'388AC62A');
        foreach (self::BLOCKS as [$marker, $start, $end]) {
            $bin->words($marker - 8, [$start >> 16, $start & 0xFFFF, $end >> 16, $end & 0xFFFF], true)->ascii($marker, self::MAGIC);
        }
        $code = [...self::lisAddi(5, 0x8FC9C6), 0x48000001, ...self::lisAddi(5, 0x8F1000), 0x48000001];
        $bin->ascii(0xB1000, pack('N*', ...$code));
        self::table($bin, self::RAIL, 16, 3000);
        self::table($bin, self::file(0x8F1000), 8, 1000);
        self::table($bin, self::file(0x8F2000), 8, 1000);

        if ($multimapSites) {
            foreach (self::MULTIMAP_SITES as [$table, $n, $lis, $register]) {
                [$high, $low] = self::lisAddi($register, $table);
                // boost target 1 has one instruction between its lis and addi
                $bin->ascii(self::file($lis), pack('N', $high))->ascii(self::file($table === 0x8F9196 ? $lis + 8 : $lis + 4), pack('N', $low));
                match (true) {
                    $table === 0x8F93DA => self::table($bin, self::file($table), 13, 1500, 10),
                    $n === 16 => self::table($bin, self::file($table), 16, 1000),
                    default => self::curve($bin, self::file($table), $n, 4500),
                };
            }
        }

        return self::closeBlocks($bin->data());
    }

    public static function file(int $cpu): int
    {
        return $cpu < 0x800000 ? $cpu : $cpu - 0x790000;
    }

    /**
     * @return array{0: int, 1: int} lis rX,hi / addi rX,rX,lo
     */
    public static function lisAddi(int $register, int $address): array
    {
        return [
            (15 << 26) | ($register << 21) | ((($address + 0x8000) >> 16) & 0xFFFF),
            (14 << 26) | ($register << 21) | ($register << 16) | ($address & 0xFFFF),
        ];
    }

    /** 2D table [nx][ny][rpm][quantity][data] at a file offset. */
    private static function table(SyntheticBin $bin, int $at, int $n, int $base, ?int $ny = null): void
    {
        $ny ??= $n;
        $rpm = array_map(fn (int $i): int => 500 + 300 * $i, range(0, $n - 1));
        $quantity = array_map(fn (int $i): int => 500 * $i, range(0, $ny - 1));
        $data = [];
        foreach (range(0, $n - 1) as $r) {
            foreach (range(0, $ny - 1) as $q) {
                $data[] = $base + 300 * $r + 200 * $q;
            }
        }
        $bin->words($at, [$n, $ny, ...$rpm, ...$quantity, ...$data], true);
    }

    /** Curve [n][rpm][values] at a file offset. */
    private static function curve(SyntheticBin $bin, int $at, int $n, int $value): void
    {
        $rpm = array_map(fn (int $i): int => 500 + 225 * $i, range(0, $n - 1));
        $bin->words($at, [$n, ...$rpm, ...array_fill(0, $n, $value)], true);
    }

    private static function closeBlocks(string $data): string
    {
        foreach (self::BLOCKS as [, $start, $end]) {
            $s = self::file($start);
            $e = self::file($end);
            $data = substr_replace($data, "\0\0\0\0", $e - 3, 4);
            $sum = array_sum(unpack('N*', substr($data, $s, $e + 1 - $s))) & 0xFFFFFFFF;
            $data = substr_replace($data, pack('N', (0xD01FE500 - $sum) & 0xFFFFFFFF), $e - 3, 4);
        }

        return $data;
    }
}
