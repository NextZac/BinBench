<?php

namespace App\Ecu\Dtc;

use App\Ecu\Bin;

/**
 * Bosch EDC15C BMW DDE4 (M47/M57, C167, 512 KB, flash mapped at 0x80000).
 *
 * The fault manager keeps one 0x22-byte calibration block per fault path ("fbwS<name>"):
 *   +0x00 u16 SPC  code stored in fault memory (SAE-style hex, 0x0335 = P0335)
 *   +0x02 u8  PRI  priority (bits 0-1) and diagnostic-lamp control
 *   +0x03 u8  ENT  debounce mode: low nibble = entry, high nibble = healing; 0 = never stored
 *   +0x04 u8  FLC  entry counter start, +0x05 u8 HLC healing counter start
 *   +0x06 u16 BCO  blink code
 *   +0x08 u16 CC0..CC7  CARB (OBD) codes per fault bit
 *   +0x18 u16 UB1..UB4  freeze-frame signal selection
 * followed by the debounce triples of the path's fault types.
 *
 * The path list itself is in the program area: at start-up the code copies, for every path,
 * the word behind a pointer-table entry into a RAM array (DPP2 = table page, r0 = table,
 * r1 = RAM array, r2 = r0 + 2 * paths). We find that loop, then follow table entry -> pointer
 * word -> path block, all DPP0-relative in the calibration page.
 */
class Edc15DtcLocator implements DtcLocator
{
    private const FLASH_BASE = 0x80000;

    private const BLOCK_SIZE = 0x22;

    /** Data area of the 512 KB DDE4 flash (calibration 0x70000-0x7BFFF and what follows). */
    private const CALIBRATION_START = 0x70000;

    /** scxt DPP2,#page / mov r0,#table / mov r1,#ram / mov r2,r0 / add r2,#len / cmp r0,r2 / jmpr nc / mov r3,[r0+] / mov [r1+],[r3] */
    private const INIT_LOOP = 'C6 02 XX 00 E6 F0 XX XX E6 F1 XX XX F0 20 06 F2 XX XX 40 02 9D 03 98 30 D8 13';

    /**
     * Names for the paths of the M57/M47 DDE4 software this was checked on (1037351632, 1037351761),
     * keyed by path number and only used when the stored code matches.
     *
     * @var array<int, array{0: int, 1: string}>
     */
    private const KNOWN = [
        0 => [0x1640, 'EEPROM (EEP)'],
        1 => [0x0605, 'ECU processor / watchdog (RUC)'],
        2 => [0x0335, 'Crankshaft speed sensor (DZG)'],
        3 => [0x1E25, 'Crank-angle tracking / camshaft (IWZ)'],
        4 => [0x2800, 'Battery voltage (UBT)'],
        5 => [0x1E45, 'Injector driver capacitor voltage bank 1 (UC1)'],
        6 => [0x1E50, 'Injector driver capacitor voltage bank 2 (UC2)'],
        7 => [0x0190, 'Rail pressure sensor (KDF)'],
        8 => [0x1190, 'Rail pressure monitoring (KDP)'],
        9 => [0x0120, 'Accelerator pedal sensor 1 (PWG)'],
        10 => [0x0220, 'Accelerator pedal sensor 2 (PGS)'],
        11 => [0x1E40, 'ADC test voltage (TST)'],
        12 => [0x0115, 'Coolant temperature sensor (WTF)'],
        13 => [0x0180, 'Fuel temperature sensor (KTF)'],
        14 => [0x0195, 'Oil temperature sensor (OTF)'],
        15 => [0x0110, 'Intake air temperature sensor (LTF)'],
        16 => [0x1490, 'Exhaust temperature sensor 1 (AT1)'],
        17 => [0x1495, 'Exhaust temperature sensor 2 (AT2)'],
        18 => [0x0235, 'Boost pressure sensor (LDF)'],
        19 => [0x0105, 'Atmospheric pressure sensor (ADF)'],
        20 => [0x0100, 'Mass airflow sensor (LMM)'],
        21 => [0x1255, 'Fuel supply pressure sensor (VDF)'],
        22 => [0x1260, 'Fuel supply pressure monitoring (VDP)'],
        23 => [0x0EF1, 'Rail pressure valve current (IDV)'],
        24 => [0x1E55, 'Sensor supply voltage 1 (UG1)'],
        25 => [0x1E60, 'Sensor supply voltage 2 (UG2)'],
        26 => [0x1E05, 'Vehicle speed output (FGA)'],
        27 => [0x0500, 'Vehicle speed sensor (FGG)'],
        28 => [0x1E30, 'Boost actuator (LDS)'],
        29 => [0x3505, 'Glow plug relay (GRS)'],
        30 => [0x0480, 'Cooling fan (MLS)'],
        31 => [0x3520, 'Engine mount actuator (MML)'],
        32 => [0x0400, 'EGR actuator (ARS)'],
        33 => [0x1630, 'High-pressure pump element shutoff (KHP)'],
        34 => [0x1195, 'Rail pressure control valve (KDR)'],
        35 => [0x3515, 'Air-conditioning compressor (KLI)'],
        36 => [0x3500, 'Radiator air outlet flap (AKS)'],
        37 => [0x1480, 'NOx catalyst fuel dosing (NOX)'],
        38 => [0x3525, 'Auxiliary heater (ZHR)'],
        39 => [0x1621, 'Controlled thermostat (THS)'],
        40 => [0x1250, 'Electric fuel pump (EKP)'],
        41 => [0x1DF0, 'Fuel shutoff device (EAB)'],
        42 => [0x18F0, 'Glow indicator (GAZ)'],
        43 => [0x3000, 'Brake switch (BRE)'],
        44 => [0x3010, 'Clutch switch (KUP)'],
        45 => [0x0420, 'Catalyst monitoring (KAT)'],
        46 => [0x1E10, 'Glow plug monitoring (GZ1)'],
        47 => [0x1E15, 'Glow control unit (GZ2)'],
        48 => [0x3510, 'Main relay (HRL)'],
        49 => [0x0560, 'Voltage stabilizer (STB)'],
        50 => [0x3005, 'Ignition terminal 15 (K15)'],
        51 => [0x0600, 'CAN communication (CAN)'],
        52 => [0x1E00, 'External CAN intervention (EXT)'],
        53 => [0x1E20, 'Maximum speed limiter (HGB)'],
        54 => [0x0300, 'Misfire monitoring (AUZ)'],
        55 => [0x0200, 'Injector driver cylinder 1 (I11)'],
        56 => [0x0202, 'Injector driver I21'],
        57 => [0x0204, 'Injector driver I31'],
        58 => [0x0206, 'Injector driver I41'],
        59 => [0x0201, 'Injector driver I12'],
        60 => [0x0203, 'Injector driver I22'],
        61 => [0x0205, 'Injector driver I32'],
        62 => [0x0207, 'Injector driver I42'],
        63 => [0x1E35, 'After-run self-test (NLT)'],
        64 => [0x1DF5, 'Immobilizer (EWS)'],
        69 => [0x19E6, 'Oil pressure switch (ODS)'],
        70 => [0x1A18, 'Ambient temperature (TUM)'],
        71 => [0x1A0E, 'Coolant heater diagnostic circuit (KW2)'],
        73 => [0x1470, 'Boost additional actuator circuit (LX1)'],
        74 => [0x0404, 'EGR additional actuator circuit (AX1)'],
        75 => [0x19FA, 'Engine shutoff flap (ASK)'],
        76 => [0x1A22, 'Swirl flap actuator (DRA)'],
        77 => [0x1613, 'Idle speed control (LRR)'],
        78 => [0x1612, 'Diagnostic warning lamp (DLA)'],
    ];

    public function locate(Bin $bin): ?DtcTable
    {
        if ($bin->size !== 0x80000) {
            return null;
        }
        $loop = $bin->findPattern(self::INIT_LOOP, 0, 2);
        if (count($loop) !== 1) {
            return null;
        }
        $at = $loop[0];
        $table = $this->physToFile($bin->u8($at + 2) * 0x4000 + ($bin->u16($at + 6) & 0x3FFF), $bin);
        $count = intdiv($bin->u16($at + 16), 2);
        if ($table === null || $count < 10 || $count > 255) {
            return null;
        }

        $paths = null;
        foreach ($this->dpp0Pages($bin) as $dpp0) {
            $paths = $this->paths($bin, $table, $count, $dpp0);
            if ($paths !== null) {
                break;
            }
        }
        if ($paths === null) {
            return null;
        }

        return new DtcTable(
            paths: $paths,
            codeFormat: 'pcode-hex',
            notes: [
                sprintf('Path list at 0x%X (%d paths), found from the start-up loop at 0x%X that copies the path pointers to RAM.', $table, $count, $at),
                'Each path has a 0x22-byte block in the calibration: stored code, priority, debounce mode, counters, blink code and 8 CARB codes.',
                'The stored code (SPC) is what the tester reads; BMW uses SAE-style values, so manufacturer codes such as 1E20 show as P1E20.',
                'Debounce mode 0 is checked by the fault-memory code before an entry is created: the path is then never stored.',
                'Debounce mode 0 only stops the fault memory entry; the substitute reaction (limp mode, fuel limit) of the fault still happens.',
            ],
            fieldHelp: [
                'ent' => 'Debounce mode (Entprellmodus). Low nibble = how the fault is entered, high nibble = how it heals. 0 = the fault is never written to fault memory.',
                'pri' => 'Priority and lamp control. Bits 0-1 decide which entry is replaced when the 10-entry memory is full; bit 1 is copied into the stored entry. 0 = lowest priority, no lamp request.',
            ],
            codeSize: 2,
            bigEndian: false,
        );
    }

    /**
     * Follows every path-list entry to its calibration block. Null unless (nearly) all blocks land
     * in the calibration area, which also tells whether the DPP0 page guess is right.
     *
     * @return FaultPath[]|null
     */
    private function paths(Bin $bin, int $table, int $count, int $dpp0): ?array
    {
        $paths = [];
        $outside = 0;
        for ($i = 0; $i < $count; $i++) {
            $entry = $this->dpp0ToFile($bin->u16($table + 2 * $i), $dpp0, $bin);
            if ($entry === null || $entry < self::CALIBRATION_START) {
                $outside++;

                continue;
            }
            $pointer = $bin->u16($entry);
            if ($pointer & 1) {
                // the fault-memory code skips paths whose pointer has bit 0 set (path not present)
                continue;
            }
            $block = $this->dpp0ToFile($pointer, $dpp0, $bin);
            if ($block === null || $block < self::CALIBRATION_START || $block + self::BLOCK_SIZE > $bin->size) {
                $outside++;

                continue;
            }
            $paths[] = $this->path($bin, $i, $block);
        }

        return count($paths) >= 10 && $outside <= intdiv($count, 10) ? $paths : null;
    }

    private function path(Bin $bin, int $index, int $block): FaultPath
    {
        $codes = [['slot' => 'spc', 'address' => $block, 'code' => $bin->u16($block)]];
        for ($bit = 0; $bit < 8; $bit++) {
            $address = $block + 8 + 2 * $bit;
            $codes[] = ['slot' => 'carb'.$bit, 'address' => $address, 'code' => $bin->u16($address)];
        }

        $known = self::KNOWN[$index] ?? null;
        $name = $known !== null && $known[0] === $bin->u16($block) ? $known[1] : null;

        return new FaultPath(
            index: $index,
            codes: $codes,
            fields: [
                ['key' => 'ent', 'label' => 'Debounce mode', 'address' => $block + 3, 'size' => 1, 'value' => $bin->u8($block + 3), 'offValue' => 0],
                ['key' => 'pri', 'label' => 'Priority / lamp', 'address' => $block + 2, 'size' => 1, 'value' => $bin->u8($block + 2), 'offValue' => 0],
            ],
            name: $name,
        );
    }

    /**
     * Pages the code loads into DPP0 (mov DPP0,#page), most used first; the calibration data is
     * addressed through DPP0.
     *
     * @return int[]
     */
    private function dpp0Pages(Bin $bin): array
    {
        $pages = [];
        foreach ($bin->findPattern('E6 00 XX 00', 0, 4096) as $o) {
            $page = $bin->u8($o + 2);
            if ($o % 2 === 0 && $o < self::CALIBRATION_START && $page >= 0x20 && $page <= 0x3F) {
                $pages[$page] = ($pages[$page] ?? 0) + 1;
            }
        }
        arsort($pages);

        return array_keys($pages);
    }

    private function dpp0ToFile(int $word, int $dpp0, Bin $bin): ?int
    {
        // only DPP0 (bits 15-14 = 00) is used for this data
        return $word >> 14 === 0 ? $this->physToFile($dpp0 * 0x4000 + $word, $bin) : null;
    }

    private function physToFile(int $phys, Bin $bin): ?int
    {
        $file = $phys - self::FLASH_BASE;

        return $file >= 0 && $file + 1 < $bin->size ? $file : null;
    }
}
