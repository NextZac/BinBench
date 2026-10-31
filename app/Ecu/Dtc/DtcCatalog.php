<?php

namespace App\Ecu\Dtc;

/**
 * Names and groups OBD P-codes and decides which ones belong to emissions monitoring.
 *
 * Codes are handled as stored by Bosch ECUs: the four hex digits of the P-code (P0301 = 0x0301).
 * Emissions-related codes (lambda/O2, catalyst, EGR, secondary air, EVAP, misfire and fuel-trim
 * monitors, particulate filter, NOx/SCR) are reported as protected: BinBench shows them but does
 * not switch them off, because doing so on a road vehicle defeats emissions controls.
 */
class DtcCatalog
{
    /** VAG manufacturer ranges (P1xxx) that only mean "emissions" on VAG software. */
    private const VAG_RANGES = [[0x1102, 0x1199], [0x1400, 0x1450]];

    /** Ranges of emissions-related codes, inclusive, as stored hex values. */
    private const EMISSION_RANGES = [
        [0x0030, 0x0064, 'Oxygen sensor heater'],
        [0x0130, 0x0167, 'Oxygen sensor'],
        [0x0170, 0x0175, 'Fuel trim (mixture adaptation)'],
        [0x0300, 0x0312, 'Misfire monitor'],
        [0x0400, 0x0459, 'EGR / secondary air / catalyst / EVAP'],
        [0x0465, 0x0479, 'EVAP purge / exhaust pressure'],
        [0x0486, 0x0499, 'EGR / EVAP / secondary air'],
        [0x1102, 0x1199, 'Oxygen sensor / mixture (manufacturer)'],
        [0x1400, 0x1450, 'EGR / EVAP / secondary air (manufacturer)'],
        [0x2000, 0x20FF, 'Exhaust after-treatment (catalyst, DPF, NOx, SCR)'],
        [0x2177, 0x2198, 'Mixture / oxygen sensor'],
        [0x2200, 0x2214, 'NOx sensor'],
        [0x2237, 0x2270, 'Oxygen sensor'],
        [0x2400, 0x24FF, 'EVAP / EGR / DPF / secondary air'],
        [0x2A00, 0x2A07, 'Oxygen sensor'],
    ];

    /** Short descriptions for common generic codes (BinBench's own wording). */
    private const NAMES = [
        0x0087 => 'Fuel rail pressure too low',
        0x0088 => 'Fuel rail pressure too high',
        0x0089 => 'Fuel pressure regulator performance',
        0x0100 => 'Air mass sensor circuit',
        0x0101 => 'Air mass sensor range/performance',
        0x0102 => 'Air mass sensor signal low',
        0x0103 => 'Air mass sensor signal high',
        0x0105 => 'Manifold pressure sensor circuit',
        0x0106 => 'Manifold pressure sensor range/performance',
        0x0107 => 'Manifold pressure sensor signal low',
        0x0108 => 'Manifold pressure sensor signal high',
        0x0110 => 'Intake air temperature sensor circuit',
        0x0112 => 'Intake air temperature sensor signal low',
        0x0113 => 'Intake air temperature sensor signal high',
        0x0115 => 'Coolant temperature sensor circuit',
        0x0116 => 'Coolant temperature sensor range/performance',
        0x0117 => 'Coolant temperature sensor signal low',
        0x0118 => 'Coolant temperature sensor signal high',
        0x0120 => 'Throttle/pedal position sensor A circuit',
        0x0121 => 'Throttle/pedal position sensor A range/performance',
        0x0122 => 'Throttle/pedal position sensor A signal low',
        0x0123 => 'Throttle/pedal position sensor A signal high',
        0x0125 => 'Coolant too cold for closed-loop control',
        0x0128 => 'Thermostat: coolant below regulating temperature',
        0x0130 => 'Oxygen sensor bank 1 sensor 1 circuit',
        0x0133 => 'Oxygen sensor bank 1 sensor 1 slow response',
        0x0135 => 'Oxygen sensor heater bank 1 sensor 1',
        0x0136 => 'Oxygen sensor bank 1 sensor 2 circuit',
        0x0141 => 'Oxygen sensor heater bank 1 sensor 2',
        0x0171 => 'Mixture too lean, bank 1',
        0x0172 => 'Mixture too rich, bank 1',
        0x0174 => 'Mixture too lean, bank 2',
        0x0175 => 'Mixture too rich, bank 2',
        0x0190 => 'Fuel rail pressure sensor circuit',
        0x0191 => 'Fuel rail pressure sensor range/performance',
        0x0201 => 'Injector circuit, cylinder 1',
        0x0202 => 'Injector circuit, cylinder 2',
        0x0203 => 'Injector circuit, cylinder 3',
        0x0204 => 'Injector circuit, cylinder 4',
        0x0205 => 'Injector circuit, cylinder 5',
        0x0206 => 'Injector circuit, cylinder 6',
        0x0217 => 'Engine overheating',
        0x0219 => 'Engine overspeed',
        0x0220 => 'Throttle/pedal position sensor B circuit',
        0x0221 => 'Throttle/pedal position sensor B range/performance',
        0x0222 => 'Throttle/pedal position sensor B signal low',
        0x0223 => 'Throttle/pedal position sensor B signal high',
        0x0230 => 'Fuel pump relay circuit',
        0x0234 => 'Overboost',
        0x0235 => 'Boost pressure sensor circuit',
        0x0236 => 'Boost pressure sensor range/performance',
        0x0237 => 'Boost pressure sensor signal low',
        0x0238 => 'Boost pressure sensor signal high',
        0x0243 => 'Wastegate solenoid circuit',
        0x0245 => 'Wastegate solenoid signal low',
        0x0246 => 'Wastegate solenoid signal high',
        0x0299 => 'Underboost',
        0x0300 => 'Random misfire',
        0x0301 => 'Misfire, cylinder 1',
        0x0302 => 'Misfire, cylinder 2',
        0x0303 => 'Misfire, cylinder 3',
        0x0304 => 'Misfire, cylinder 4',
        0x0305 => 'Misfire, cylinder 5',
        0x0306 => 'Misfire, cylinder 6',
        0x0321 => 'Engine speed sensor range/performance',
        0x0322 => 'Engine speed sensor no signal',
        0x0325 => 'Knock sensor 1 circuit',
        0x0327 => 'Knock sensor 1 signal low',
        0x0328 => 'Knock sensor 1 signal high',
        0x0330 => 'Knock sensor 2 circuit',
        0x0332 => 'Knock sensor 2 signal low',
        0x0335 => 'Crankshaft position sensor circuit',
        0x0336 => 'Crankshaft position sensor range/performance',
        0x0340 => 'Camshaft position sensor circuit',
        0x0341 => 'Camshaft position sensor range/performance',
        0x0351 => 'Ignition coil 1 circuit',
        0x0352 => 'Ignition coil 2 circuit',
        0x0353 => 'Ignition coil 3 circuit',
        0x0354 => 'Ignition coil 4 circuit',
        0x0355 => 'Ignition coil 5 circuit',
        0x0356 => 'Ignition coil 6 circuit',
        0x0380 => 'Glow plug circuit',
        0x0400 => 'EGR flow',
        0x0401 => 'EGR flow too low',
        0x0402 => 'EGR flow too high',
        0x0410 => 'Secondary air system',
        0x0411 => 'Secondary air flow incorrect',
        0x0420 => 'Catalyst efficiency below threshold, bank 1',
        0x0430 => 'Catalyst efficiency below threshold, bank 2',
        0x0440 => 'EVAP system',
        0x0441 => 'EVAP purge flow incorrect',
        0x0443 => 'EVAP purge valve circuit',
        0x0480 => 'Cooling fan 1 control circuit',
        0x0481 => 'Cooling fan 2 control circuit',
        0x0500 => 'Vehicle speed sensor',
        0x0501 => 'Vehicle speed sensor range/performance',
        0x0505 => 'Idle control system',
        0x0506 => 'Idle speed lower than expected',
        0x0507 => 'Idle speed higher than expected',
        0x0560 => 'System voltage',
        0x0562 => 'System voltage low',
        0x0563 => 'System voltage high',
        0x0571 => 'Brake switch A circuit',
        0x0600 => 'CAN communication link',
        0x0601 => 'Control unit memory checksum',
        0x0604 => 'Control unit RAM',
        0x0605 => 'Control unit ROM',
        0x0606 => 'Control unit processor',
        0x0638 => 'Throttle actuator range/performance',
        0x0700 => 'Transmission control system',
        0x0760 => 'Shift solenoid C',
        0x2101 => 'Throttle actuator motor range/performance',
        0x2135 => 'Throttle/pedal sensor A/B correlation',
        0x2138 => 'Pedal position sensor D/E correlation',
    ];

    /**
     * Stored value as code text using the SAE two-byte layout: the top two bits are the letter
     * (P, C, B, U), the rest the digits. 0x0301 → "P0301", 0xC120 → "U0120".
     */
    public static function format(int $code): string
    {
        $code &= 0xFFFF;

        return 'PCBU'[$code >> 14].strtoupper(str_pad(dechex($code & 0x3FFF), 4, '0', STR_PAD_LEFT));
    }

    /** 0x0000 and 0xFFFF mark an unused code slot. */
    public static function isUnused(int $code): bool
    {
        return $code === 0 || $code === 0xFFFF;
    }

    /**
     * Description, system group and whether the code is emissions-related.
     *
     * @return array{code: string, name: string, system: string, emission: bool, emissionReason: ?string}
     */
    public static function describe(int $code, bool $vagCodes = true): array
    {
        $reason = self::emissionReason($code, $vagCodes);

        return [
            'code' => self::format($code),
            'name' => self::NAMES[$code] ?? (($code & 0x3FFF) >= 0x1000 && ($code & 0x3FFF) < 0x2000 || ($code & 0x3FFF) >= 0x3000 ? 'Manufacturer-specific code' : self::system($code)),
            'system' => self::system($code),
            'emission' => $reason !== null,
            'emissionReason' => $reason,
        ];
    }

    public static function isEmission(int $code, bool $vagCodes = true): bool
    {
        return self::emissionReason($code, $vagCodes) !== null;
    }

    /**
     * Why a code is emissions-related, or null. $vagCodes applies the VAG meaning of P1xxx codes.
     */
    public static function emissionReason(int $code, bool $vagCodes = true): ?string
    {
        if ($code >= 0x4000 || self::isUnused($code)) {
            return null;
        }
        foreach (self::EMISSION_RANGES as [$from, $to, $what]) {
            if (! $vagCodes && self::inVagRange($code)) {
                continue;
            }
            if ($code >= $from && $code <= $to) {
                return $what;
            }
        }

        return null;
    }

    private static function inVagRange(int $code): bool
    {
        foreach (self::VAG_RANGES as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return true;
            }
        }

        return false;
    }

    /** Broad system group from the code's range. */
    public static function system(int $code): string
    {
        if ($code >= 0x4000) {
            return ['', 'Chassis', 'Body', 'Network'][$code >> 14];
        }
        $group = $code >> 8;

        return match (true) {
            $code >= 0x1000 && $code < 0x2000 => 'Manufacturer-specific',
            $code >= 0x3000 => 'Manufacturer-specific',
            $code >= 0x2000 => 'Powertrain (extended)',
            $group <= 0x02 => 'Fuel and air metering',
            $group === 0x03 => 'Ignition, misfire and engine position',
            $group === 0x04 => 'Auxiliary emission controls',
            $group === 0x05 => 'Vehicle speed, idle and inputs',
            $group === 0x06 => 'Control unit and outputs',
            default => 'Transmission',
        };
    }
}
