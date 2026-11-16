<?php

namespace App\Ecu\Patches;

use App\Ecu\Bin;
use App\Ecu\Families\Edc16\BmwEdc16cLayout;
use App\Ecu\Families\Edc16\Dde5MapCatalog;
use App\Ecu\Maps\MapDef;

/**
 * Two maps on the cruise control I/O switch for BMW EDC16C (DDE5) 1037361878 (X5 E53 3.0d).
 *
 * Every switched table is loaded by "lis rX,hi / addi rX,rX,lo" right before the lookup call. The
 * addi is replaced by a branch to a stub that loads the table address from a two-entry pointer
 * table, indexed by the cruise I/O flag:
 *
 *   lbz    rX, -0x75CC(r13)       ; 0x3FA224 cruise I/O flag, 0 or 1 (its only writer: 0x84B3E8)
 *   rlwinm rX, rX, 2, 29, 29      ; 0 or 4
 *   addis  rX, rX, ptr@ha
 *   lwz    rX, ptr@l(rX)          ; [stock table, Map 2 copy]
 *   b      site + 4
 *
 * Only rX is changed (the register the lookup call reads next); CR, LR and CTR are untouched.
 * Stubs, pointer tables and Map 2 copies all live in free external application flash from
 * 0x875000, so only the external application checksum block changes; internal flash and the data
 * block stay as they are.
 *
 * Unlike the DDE4 multimap, this code has not run on a car.
 */
class Dde5CruiseSwitchMultimap implements CodePatch
{
    private const SOFTWARE = ['1037361878'];

    /** RAM offset of the cruise I/O flag from r13 (0x3FA224). */
    private const FLAG = -0x75CC;

    private const CODE = 0x875000;

    private const POINTERS = 0x875100;

    private const COPIES = 0x875200;

    /** End of the area the patch may use (exclusive). Free flash runs to 0x87FF00. */
    private const AREA_END = 0x877000;

    private const STUB_SIZE = 20;

    /**
     * Switched tables: [CPU header, length, lis site, addi site, register].
     *
     * @var array<int, array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    private const TABLES = [
        [0x8E8236, 86, 0x856248, 0x85624C, 4],    // full-load torque limit A
        [0x8E82E2, 86, 0x856260, 0x856264, 4],    // full-load torque limit B
        [0x8E8362, 86, 0x8562AC, 0x8562B0, 4],    // second torque limit A
        [0x8E8338, 42, 0x8562C4, 0x8562C8, 4],    // second torque limit B
        [0x8E984C, 580, 0x85546C, 0x855470, 5],   // smoke λ min A1
        [0x8E916C, 580, 0x855488, 0x85548C, 5],   // smoke λ min A2
        [0x8E9A90, 580, 0x8554A8, 0x8554AC, 5],   // smoke λ min B1
        [0x8E93B0, 580, 0x8554C4, 0x8554C8, 5],   // smoke λ min B2
        [0x8F9196, 580, 0x86EF40, 0x86EF48, 5],   // boost pressure target 1
        [0x8F8D0E, 580, 0x86EF60, 0x86EF64, 5],   // boost pressure target 2
        [0x8F8F52, 580, 0x86EF7C, 0x86EF80, 5],   // boost pressure target 3
        [0x8F93DA, 310, 0x86EF98, 0x86EF9C, 5],   // boost pressure limit
    ];

    public function id(): string
    {
        return 'dde5-multimap-2';
    }

    public function familyId(): string
    {
        return 'edc16';
    }

    public function describe(): array
    {
        return [
            'name' => 'Multimap: 2 maps on the cruise I/O switch (DDE5)',
            'summary' => 'Cruise control switched on (I/O) selects Map 2, switched off runs Map 1, the file’s normal tables.',
            'details' => [
                'Switches the full-load and second torque limits, the four smoke limiter λ-min maps, the three boost pressure targets and the boost pressure limit.',
                'Map 2 starts as a copy of Map 1, so nothing changes until you edit the “Map 2” tables (Multimap category).',
                'Rail pressure and injection timing are not switched: they are read in internal flash, which this patch leaves untouched.',
                'The switch is the flag the ECU sends as the cruise lamp (CAN 0x545). Which λ-min pair and boost target the engine actually uses is not confirmed, so all of them are switched.',
            ],
            'software' => self::SOFTWARE,
            'tested' => 'Not run on a car yet. Stub code written for this software and checked in the disassembly.',
            'flash' => 'Changes external program flash: write it with BDM or boot mode. OBD programming checks a signature that BinBench does not update. Save with checksum correction on.',
        ];
    }

    public function status(Bin $bin): array
    {
        if (! BmwEdc16cLayout::matches($bin)) {
            return $this->unavailable('Needs a full 1.5 MB read of a BMW EDC16C (internal + external flash).');
        }
        $software = $this->software($bin);
        if (! in_array($software, self::SOFTWARE, true)) {
            return $this->unavailable(sprintf('Written for DDE5 software %s; this file is %s.', implode(', ', self::SOFTWARE), $software ?? 'unknown'));
        }

        $installed = $this->file($bin, self::CODE, strlen($this->code())) === $this->code()
            && $this->file($bin, self::POINTERS, strlen($this->pointers())) === $this->pointers();
        foreach (self::TABLES as $i => [, , , $addi]) {
            $installed = $installed && $bin->u32($this->offset($addi), true) === $this->branch($addi, self::CODE + $i * self::STUB_SIZE);
        }
        if ($installed) {
            return ['status' => 'installed', 'reason' => null];
        }

        foreach (self::TABLES as [$header, $length, $lis, $addi, $register]) {
            if ($bin->u32($this->offset($lis), true) !== $this->lis($register, $header) || $bin->u32($this->offset($addi), true) !== $this->addi($register, $header)) {
                return $this->unavailable(sprintf('Code at 0x%06X is not the stock table load (another patch or a different software build).', $addi));
            }
            if ($this->tableLength($bin, $header) !== $length) {
                return $this->unavailable(sprintf('The table at 0x%06X does not have its stock size.', $header));
            }
        }
        $area = $this->file($bin, self::CODE, self::AREA_END - self::CODE);
        if (strspn($area, "\xFF") !== strlen($area)) {
            return $this->unavailable(sprintf('Free flash at 0x%06X-0x%06X is already in use.', self::CODE, self::AREA_END));
        }

        return ['status' => 'available', 'reason' => null];
    }

    public function install(Bin $bin): array
    {
        $writes = [
            ['address' => $this->offset(self::CODE), 'bytes' => $this->code()],
            ['address' => $this->offset(self::POINTERS), 'bytes' => $this->pointers()],
        ];
        foreach (self::TABLES as $i => [$header, $length, , $addi]) {
            // Map 2 starts as a copy of the file's own table
            $writes[] = ['address' => $this->offset($this->copyAddress($i)), 'bytes' => $bin->slice($this->offset($header), $length)];
            $writes[] = ['address' => $this->offset($addi), 'bytes' => pack('N', $this->branch($addi, self::CODE + $i * self::STUB_SIZE))];
        }

        return $writes;
    }

    public function uninstall(Bin $bin): array
    {
        $writes = [['address' => $this->offset(self::CODE), 'bytes' => str_repeat("\xFF", self::AREA_END - self::CODE)]];
        foreach (self::TABLES as [$header, , , $addi, $register]) {
            $writes[] = ['address' => $this->offset($addi), 'bytes' => pack('N', $this->addi($register, $header))];
        }

        return $writes;
    }

    public function maps(Bin $bin): array
    {
        $software = (string) $this->software($bin);
        $maps = [];
        foreach (self::TABLES as $i => [$header]) {
            foreach ([[null, 'Map 1', 'Used while cruise control is off.'], [$this->offset($this->copyAddress($i)), 'Map 2', 'Used while cruise control is switched on (I/O).']] as [$at, $prefix, $note]) {
                $map = Dde5MapCatalog::map($bin, $software, $header, $at);
                if ($map === null) {
                    continue;
                }
                // rebuilt so the id follows the new name
                $maps[] = MapDef::fromArray([...$map->toArray(), 'id' => null, 'name' => $prefix.' · '.$map->name,
                    'category' => 'Multimap', 'source' => 'patch', 'description' => $note.' '.$map->description]);
            }
        }

        return $maps;
    }

    /** Stubs, one per table, STUB_SIZE bytes each. */
    private function code(): string
    {
        $code = '';
        foreach (self::TABLES as $i => [, , , $addi, $r]) {
            $at = self::CODE + $i * self::STUB_SIZE;
            $pointer = self::POINTERS + 8 * $i;
            $code .= pack('N5',
                (34 << 26) | ($r << 21) | (13 << 16) | (self::FLAG & 0xFFFF),          // lbz    rX, FLAG(r13)
                (21 << 26) | ($r << 21) | ($r << 16) | (2 << 11) | (29 << 6) | (29 << 1), // rlwinm rX, rX, 2, 29, 29
                (15 << 26) | ($r << 21) | ($r << 16) | $this->high($pointer),         // addis  rX, rX, ptr@ha
                (32 << 26) | ($r << 21) | ($r << 16) | ($pointer & 0xFFFF),           // lwz    rX, ptr@l(rX)
                $this->branch($at + 16, $addi + 4),                                   // b      site + 4
            );
        }

        return $code;
    }

    /** Per table: [stock address, Map 2 copy address]. */
    private function pointers(): string
    {
        $out = '';
        foreach (self::TABLES as $i => [$header]) {
            $out .= pack('N2', $header, $this->copyAddress($i));
        }

        return $out;
    }

    private function copyAddress(int $index): int
    {
        $at = self::COPIES;
        for ($i = 0; $i < $index; $i++) {
            $at += (self::TABLES[$i][1] + 3) & ~3;
        }

        return $at;
    }

    private function tableLength(Bin $bin, int $header): int
    {
        $at = $this->offset($header);
        $nx = $bin->u16($at, true);
        $second = $bin->u16($at + 2, true);
        // a 2D header holds two small counts, a curve's second word is its first breakpoint
        $ny = $second <= 48 && $second >= 2 && $bin->s16($at + 4 + 2 * $nx, true) < $bin->s16($at + 6 + 2 * $nx, true) ? $second : 0;

        return $ny > 0 ? 4 + 2 * ($nx + $ny + $nx * $ny) : 2 + 4 * $nx;
    }

    private function lis(int $register, int $address): int
    {
        return (15 << 26) | ($register << 21) | $this->high($address);
    }

    private function addi(int $register, int $address): int
    {
        return (14 << 26) | ($register << 21) | ($register << 16) | ($address & 0xFFFF);
    }

    /** Upper half adjusted for the sign of the lower half (@ha). */
    private function high(int $address): int
    {
        return (($address + 0x8000) >> 16) & 0xFFFF;
    }

    /** "b target" placed at $from. */
    private function branch(int $from, int $to): int
    {
        return (18 << 26) | (($to - $from) & 0x3FFFFFC);
    }

    private function offset(int $cpu): int
    {
        return (int) BmwEdc16cLayout::cpuToFile($cpu);
    }

    private function file(Bin $bin, int $cpu, int $length): string
    {
        return $bin->slice($this->offset($cpu), $length);
    }

    private function software(Bin $bin): ?string
    {
        return preg_match('/1037\d{6}/', $bin->slice(0x150054, 10), $m) ? $m[0] : null;
    }

    /**
     * @return array{status: 'unavailable', reason: string}
     */
    private function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
