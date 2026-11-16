<?php

namespace App\Ecu\Patches;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;

/**
 * Multimap for BMW DDE4 / EDC15C4 (M57, C167). Hook instructions that load a table pointer are replaced
 * by `calls 0Bh,stub`; each stub picks the table of the active map. Code lives in the free C3 area at
 * file 0x3E000 (CPU 0xBE000, segment 0Bh), the table copies in free calibration space.
 *
 * Code and hook bytes are taken byte for byte from builds that were flashed to cars. Code for
 * 1037351632 and 1037351761 is identical at every address touched here.
 *
 * File offset = CPU address - 0x80000. 16-bit table pointers address file 0x70000 + pointer.
 */
abstract class Dde4Multimap implements CodePatch
{
    protected const IMAGE_SIZE = 0x80000;

    protected const SOFTWARE_ADDRESS = 0x7FEF0;

    protected const SOFTWARE = ['1037351632', '1037351761'];

    protected const CODE_ADDRESS = 0x3E000;

    protected const FREE = "\xC3";

    protected const CALIBRATION = 0x70000;

    /** Shared timing axes (rpm rows, injection quantity columns). */
    private const RPM_AXIS = 0x74F3C;

    private const QUANTITY_AXIS_8 = 0x74F8C;

    private const QUANTITY_AXIS_16 = 0x74FA0;

    /**
     * Tables the stubs switch. slot = pointer word at 0x70000 + slot that the stock code loads.
     *
     * @var array<string, array{name: string, slot: int, address: int, length: int, layout: string}>
     */
    protected const TABLES = [
        'vnt' => ['name' => 'Boost actuator duty limit (ldwGRmaxKF)', 'slot' => 0x13E8, 'address' => 0x73F4E, 'length' => 0xB0, 'layout' => 'vnt'],
        'adb' => ['name' => 'Launch / standstill torque limiter (mrwADB_KL)', 'slot' => 0x12D6, 'address' => 0x7A8BA, 'length' => 0x50, 'layout' => 'limiter'],
        'bdbh' => ['name' => 'Torque limiter raised (mrwBDBH_KL)', 'slot' => 0x12CC, 'address' => 0x7A910, 'length' => 0x50, 'layout' => 'limiter'],
        'bdbn' => ['name' => 'Torque limiter normal (mrwBDBN_KL)', 'slot' => 0x12CE, 'address' => 0x7A960, 'length' => 0x50, 'layout' => 'limiter'],
        'soiMax' => ['name' => 'Main injection timing earliest (zuwABHmxKF)', 'slot' => 0x108C, 'address' => 0x77D9E, 'length' => 0x100, 'layout' => 'timing8'],
        'soi2' => ['name' => 'Main injection timing without pilot (zuwABHG2KF)', 'slot' => 0x107C, 'address' => 0x7809E, 'length' => 0x200, 'layout' => 'timing16'],
    ];

    /** Where the Map 2 copies live (the stub code holds these pointers). */
    protected const MAP2 = [
        'vnt' => 0x70A00, 'adb' => 0x70AC0, 'bdbh' => 0x70B10, 'soiMax' => 0x70DA0, 'bdbn' => 0x70EA0, 'soi2' => 0x7BD00,
    ];

    /** Stub code written at CODE_ADDRESS, as hex. */
    abstract protected function code(): string;

    /**
     * Hooked instructions.
     *
     * @return array<int, array{0: int, 1: string, 2: string}> [file offset, stock hex, patched hex]
     */
    abstract protected function hooks(): array;

    /**
     * Table copies besides Map 2: [destination, bytes].
     *
     * @return array<int, array{0: int, 1: string}>
     */
    abstract protected function extraTables(Bin $bin): array;

    /**
     * Free areas the extra tables occupy: [destination, length].
     *
     * @return array<int, array{0: int, 1: int}>
     */
    abstract protected function extraAreas(): array;

    /**
     * Map definitions of the extra tables (Map 3 etc.).
     *
     * @return MapDef[]
     */
    abstract protected function extraMaps(Bin $bin): array;

    /**
     * Pointer slots that must hold their stock values besides TABLES: slot => file address.
     *
     * @return array<int, int>
     */
    protected function extraSlots(): array
    {
        return [];
    }

    public function familyId(): string
    {
        return 'edc15';
    }

    public function status(Bin $bin): array
    {
        if ($bin->size !== self::IMAGE_SIZE) {
            return $this->unavailable('Needs a full 512 KB read: the patch adds program code, which a calibration-only read does not contain.');
        }
        $software = $bin->slice(self::SOFTWARE_ADDRESS, 10);
        if (! in_array($software, self::SOFTWARE, true)) {
            return $this->unavailable(sprintf('Written for DDE4 software %s; this file is %s.', implode(' and ', self::SOFTWARE), preg_match('/^\d{10}$/', $software) ? $software : 'unknown'));
        }

        $code = (string) hex2bin($this->code());
        $codeInstalled = $bin->slice(self::CODE_ADDRESS, strlen($code)) === $code;
        $hooksInstalled = array_map(fn (array $hook): bool => bin2hex($bin->slice($hook[0], 4)) === $hook[2], $this->hooks());
        if ($codeInstalled && ! in_array(false, $hooksInstalled, true)) {
            return ['status' => 'installed', 'reason' => null];
        }

        foreach ($this->hooks() as [$address, $stock]) {
            $found = bin2hex($bin->slice($address, 4));
            if ($found === $stock) {
                continue;
            }
            if (str_starts_with($found, 'da0b')) {
                return $this->unavailable(sprintf('Another code patch is installed (call into segment 0Bh at 0x%05X). Remove it first.', $address));
            }

            return $this->unavailable(sprintf('Code at 0x%05X is not the stock instruction (found %s, expected %s).', $address, $found, $stock));
        }
        if (strspn($bin->slice(self::CODE_ADDRESS, strlen($code)), self::FREE) !== strlen($code)) {
            return $this->unavailable(sprintf('The code area at 0x%05X is already in use.', self::CODE_ADDRESS));
        }
        foreach ($this->freeAreas() as [$address, $length]) {
            if (strspn($bin->slice($address, $length), self::FREE) !== $length) {
                return $this->unavailable(sprintf('Free calibration space at 0x%05X is already in use.', $address));
            }
        }
        $slots = array_column(self::TABLES, 'address', 'slot') + $this->extraSlots();
        foreach ($slots as $slot => $address) {
            if ($bin->u16(self::CALIBRATION + $slot) !== $address - self::CALIBRATION) {
                return $this->unavailable(sprintf('The table pointer at 0x%05X does not point to 0x%05X as on stock files.', self::CALIBRATION + $slot, $address));
            }
        }

        return ['status' => 'available', 'reason' => null];
    }

    public function install(Bin $bin): array
    {
        $writes = [['address' => self::CODE_ADDRESS, 'bytes' => (string) hex2bin($this->code())]];
        foreach ($this->hooks() as [$address, , $patched]) {
            $writes[] = ['address' => $address, 'bytes' => (string) hex2bin($patched)];
        }
        // Map 2 starts as a copy of the file's own table, so the switch changes nothing until it is edited
        foreach (self::MAP2 as $key => $destination) {
            $table = self::TABLES[$key];
            $writes[] = ['address' => $destination, 'bytes' => $bin->slice($table['address'], $table['length'])];
        }
        foreach ($this->extraTables($bin) as [$destination, $bytes]) {
            $writes[] = ['address' => $destination, 'bytes' => $bytes];
        }

        return $writes;
    }

    public function uninstall(Bin $bin): array
    {
        $writes = [['address' => self::CODE_ADDRESS, 'bytes' => str_repeat(self::FREE, strlen((string) hex2bin($this->code())))]];
        foreach ($this->hooks() as [$address, $stock]) {
            $writes[] = ['address' => $address, 'bytes' => (string) hex2bin($stock)];
        }
        foreach ($this->freeAreas() as [$address, $length]) {
            $writes[] = ['address' => $address, 'bytes' => str_repeat(self::FREE, $length)];
        }

        return $writes;
    }

    public function maps(Bin $bin): array
    {
        $maps = [];
        foreach (self::TABLES as $key => $table) {
            $maps[] = $this->tableMap($key, $table['address'], 'Map 1', $this->map1Note());
            $maps[] = $this->tableMap($key, self::MAP2[$key], 'Map 2', $this->map2Note());
        }

        return [...$maps, ...$this->extraMaps($bin)];
    }

    /** Description suffix for the stock-position tables. */
    abstract protected function map1Note(): string;

    /** Description suffix for the Map 2 copies. */
    abstract protected function map2Note(): string;

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    protected function freeAreas(): array
    {
        $areas = [];
        foreach (self::MAP2 as $key => $destination) {
            $areas[] = [$destination, self::TABLES[$key]['length']];
        }

        return [...$areas, ...$this->extraAreas()];
    }

    /**
     * Map definition of one switched table (stock layout) at $base.
     */
    protected function tableMap(string $key, int $base, string $prefix, string $note): MapDef
    {
        $table = self::TABLES[$key];
        $name = $prefix.' · '.$table['name'];
        $description = $note;

        $map = match ($table['layout']) {
            'vnt' => new MapDef($name, $base + 0x30, 16, 4, 'u16', false, 0.01, 0, '%', 'Multimap', $description, 'patch', 1.0),
            'limiter' => new MapDef($name, $base + 0x2A, 1, 19, 'u16', false, 0.01, 0, 'mg/st', 'Multimap', $description, 'patch', 1.0),
            'timing8' => new MapDef($name, $base, 16, 8, 's16', false, 0.023438, 0, '°CrA', 'Multimap', $description.' Its axes are shared with the other timing maps.', 'patch', 1.0),
            'timing16' => new MapDef($name, $base, 16, 16, 's16', false, 0.023438, 0, '°CrA', 'Multimap', $description.' Its axes are shared with the other timing maps.', 'patch', 1.0),
        };
        $map->decimals = $table['layout'] === 'limiter' || $table['layout'] === 'vnt' ? 2 : 1;
        [$map->x, $map->y] = match ($table['layout']) {
            'vnt' => [$this->quantityAxis(4, $base + 0x28), $this->rpmAxis(16, $base + 0x04)],
            'limiter' => [$this->rpmAxis(19, $base + 0x04), null],
            'timing8' => [$this->quantityAxis(8, self::QUANTITY_AXIS_8), $this->rpmAxis(16, self::RPM_AXIS)],
            'timing16' => [$this->quantityAxis(16, self::QUANTITY_AXIS_16), $this->rpmAxis(16, self::RPM_AXIS)],
        };

        return $map;
    }

    protected function rpmAxis(int $count, int $address): Axis
    {
        return new Axis($count, $address, 'u16', false, 1, 0, '1/min', 'Engine speed');
    }

    protected function quantityAxis(int $count, int $address): Axis
    {
        return new Axis($count, $address, 'u16', false, 0.01, 0, 'mg/st', 'Injection quantity', null, 2);
    }

    /**
     * @return array{status: 'unavailable', reason: string}
     */
    protected function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
