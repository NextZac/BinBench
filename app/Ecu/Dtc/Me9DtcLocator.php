<?php

namespace App\Ecu\Dtc;

use App\Ecu\Bin;

/**
 * Bosch ME9.0 (MPC5xx PowerPC, big-endian, 2 MB; Ford Focus RS mk2 / Volvo five-cylinder turbo).
 *
 * Per fault path the calibration holds:
 *  - an OBD code table: 4 big-endian 16-bit codes per path (8 bytes per row, row = path number),
 *    one code per fault type in the order max / min / signal / plausibility (P0338 P0337 P0335 P0336);
 *  - a second table of the same shape with manufacturer codes, right after the OBD table;
 *  - an error-class table: 4 bytes per path, one class index per fault type.
 *
 * Both code tables are found from the routine that picks the table the tester reads:
 *   lis r12,hi / lbz r12,lo(r12) / cmpwi r12,1 / bne / lis r3,OBD / b / lis r3,MFR / stw r3,x(r13).
 * The error-class table is found from the fault-entry code, which reads the class with
 * "table + 4 * path + type" and only creates a fault-memory entry when 0 < class <= max class.
 * So class 0 means: never stored (no fault code, no MIL) for that fault type.
 */
class Me9DtcLocator implements DtcLocator
{
    /** Data is read through a mirror of the flash at 0x400000. */
    private const MIRROR = 0x400000;

    private const CODE_AREA_END = 0x1C0000;

    private const SELECT_ROUTINE = '3D 80 XX XX 89 8C XX XX 2C 0C 00 01 40 82 00 10 3C 60 XX XX 38 63 XX XX 48 00 00 0C 3C 60 XX XX 38 63 XX XX 90 6D XX XX';

    /** @var string[] fault type per code/class slot */
    private const SLOTS = ['max', 'min', 'signal', 'plausibility'];

    public function locate(Bin $bin): ?DtcTable
    {
        $select = $this->selectRoutine($bin);
        if ($select === null) {
            return null;
        }
        [$obd, $mfr, $switch] = $select;
        if ($mfr <= $obd || ($mfr - $obd) % 8 !== 0) {
            return null;
        }
        $count = intdiv($mfr - $obd, 8);
        if ($count < 50 || $count > 1000 || $mfr + 8 * $count > $bin->size) {
            return null;
        }

        $classes = $this->classTable($bin, $count);
        if ($classes === null) {
            return null;
        }
        [$classTable, $maxClass] = $classes;

        $obdActive = $switch !== null && $bin->u8($switch) === 1;
        $paths = [];
        for ($i = 1; $i < $count; $i++) {
            $path = $this->path($bin, $i, $obd, $mfr, $classTable, $obdActive);
            if ($path !== null) {
                $paths[] = $path;
            }
        }
        if (count($paths) < 20) {
            return null;
        }

        return new DtcTable(
            paths: $paths,
            codeFormat: 'pcode-hex',
            notes: [
                sprintf('OBD code table at 0x%X and manufacturer code table at 0x%X: %d rows of 4 big-endian codes, one row per fault path.', $obd, $mfr, $count),
                sprintf('Error-class table at 0x%X: one class byte per path and fault type. The fault-entry code only creates an entry for classes 1 to %d, so class 0 switches that fault type off.', $classTable, $maxClass),
                $switch !== null
                    ? sprintf('The byte at 0x%X selects which code table the tester reads (1 = OBD table, otherwise manufacturer table); it is %d here, so the %s codes are reported.', $switch, $bin->u8($switch), $obdActive ? 'OBD' : 'manufacturer')
                    : 'Could not read which code table is reported.',
                'Slots per path are the fault types max, min, signal and plausibility. 0x0000 and 0xFFFF mean no code.',
                'Paths without any code or class are left out. Path names are not stored in the dump.',
            ],
            fieldHelp: [
                'class' => 'Error class (index into the class table that holds debounce, healing and MIL behaviour). 0 = this fault type is never entered in fault memory and never reported.',
            ],
            codeSize: 2,
            bigEndian: true,
        );
    }

    private function path(Bin $bin, int $index, int $obd, int $mfr, int $classTable, bool $obdActive): ?FaultPath
    {
        $codes = [];
        $fields = [];
        $used = false;
        foreach (self::SLOTS as $slot => $type) {
            $address = $obd + 8 * $index + 2 * $slot;
            $code = $bin->u16($address, true);
            $codes[] = ['slot' => 'obd-'.$type, 'address' => $address, 'code' => $code];

            $classAddress = $classTable + 4 * $index + $slot;
            $class = $bin->u8($classAddress);
            $fields[] = ['key' => 'class-'.$type, 'label' => 'Error class '.$type, 'address' => $classAddress, 'size' => 1, 'value' => $class, 'offValue' => 0];

            $used = $used || ($code !== 0 && $code !== 0xFFFF) || $class !== 0;
        }
        if (! $obdActive) {
            // the manufacturer table is what the tester reads in this dataset
            foreach (self::SLOTS as $slot => $type) {
                $address = $mfr + 8 * $index + 2 * $slot;
                $codes[] = ['slot' => 'mfr-'.$type, 'address' => $address, 'code' => $bin->u16($address, true)];
            }
        }

        return $used ? new FaultPath(index: $index, codes: $codes, fields: $fields) : null;
    }

    /**
     * OBD table, manufacturer table and the switch byte, from the table-select routine.
     *
     * @return array{0: int, 1: int, 2: ?int}|null
     */
    private function selectRoutine(Bin $bin): ?array
    {
        $found = null;
        foreach ($bin->findPattern(self::SELECT_ROUTINE, 0, 8) as $at) {
            if ($at % 4 !== 0 || $at >= self::CODE_AREA_END) {
                continue;
            }
            $obd = $this->toFile($this->hiLo($bin, $at + 16, $at + 20), $bin);
            $mfr = $this->toFile($this->hiLo($bin, $at + 28, $at + 32), $bin);
            $switch = $this->toFile($this->hiLo($bin, $at, $at + 4), $bin);
            if ($obd === null || $mfr === null) {
                continue;
            }
            if ($found !== null && [$obd, $mfr] !== [$found[0], $found[1]]) {
                return null;
            }
            $found = [$obd, $mfr, $switch];
        }

        return $found;
    }

    /**
     * The class table: lis/addi base, then "slwi rX,path,2" (4 bytes per path) and a compare of
     * the class against the highest valid class. Every class byte must be within that range.
     *
     * @return array{0: int, 1: int}|null
     */
    private function classTable(Bin $bin, int $count): ?array
    {
        $found = null;
        for ($o = 0; $o + 8 <= min($bin->size, self::CODE_AREA_END); $o += 4) {
            $lis = $bin->u32($o, true);
            if (($lis & 0xFC1F0000) !== 0x3C000000) {
                continue;
            }
            $reg = ($lis >> 21) & 0x1F;
            $addi = $bin->u32($o + 4, true);
            if ($addi >> 26 !== 14 || (($addi >> 21) & 0x1F) !== $reg || (($addi >> 16) & 0x1F) !== $reg) {
                continue;
            }
            if (! $this->followedBy($bin, $o + 8, 3, fn (int $w): bool => ($w & 0xFC00FFFF) === 0x5400103A)) {
                continue;
            }
            $max = null;
            $this->followedBy($bin, $o + 8, 12, function (int $w) use (&$max): bool {
                $imm = $w & 0xFFFF;
                if (($w & 0xFC600000) === 0x2C000000 && $imm >= 2 && $imm < 0x80) {
                    $max = $imm;

                    return true;
                }

                return false;
            });
            if ($max === null) {
                continue;
            }
            $table = $this->toFile((($lis & 0xFFFF) << 16) + $this->simm($addi), $bin);
            if ($table === null || $table + 4 * $count > $bin->size || ! $this->classesValid($bin, $table, $count, $max)) {
                continue;
            }
            if ($found !== null && $found !== [$table, $max]) {
                return null;
            }
            $found = [$table, $max];
        }

        return $found;
    }

    private function classesValid(Bin $bin, int $table, int $count, int $max): bool
    {
        $nonZero = 0;
        foreach (str_split($bin->slice($table, 4 * $count)) as $byte) {
            $class = ord($byte);
            if ($class > $max) {
                return false;
            }
            $nonZero += $class !== 0 ? 1 : 0;
        }

        return $nonZero >= 20;
    }

    private function followedBy(Bin $bin, int $from, int $instructions, callable $test): bool
    {
        for ($i = 0; $i < $instructions && $from + 4 * $i + 4 <= $bin->size; $i++) {
            if ($test($bin->u32($from + 4 * $i, true))) {
                return true;
            }
        }

        return false;
    }

    private function hiLo(Bin $bin, int $lis, int $low): int
    {
        return ($bin->u16($lis + 2, true) << 16) + $this->simm($bin->u32($low, true));
    }

    private function simm(int $instruction): int
    {
        $imm = $instruction & 0xFFFF;

        return $imm >= 0x8000 ? $imm - 0x10000 : $imm;
    }

    private function toFile(int $address, Bin $bin): ?int
    {
        $file = $address >= self::MIRROR ? $address - self::MIRROR : $address;

        return $file >= 0 && $file < $bin->size ? $file : null;
    }
}
