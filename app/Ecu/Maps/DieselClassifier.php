<?php

namespace App\Ecu\Maps;

/**
 * Names raw AxisScanner candidates of Bosch diesel ECUs (EDC15/16/17) from the shape of their
 * axes and data, using the common Bosch raw scalings:
 * rpm 1/min, fuel quantity 0.01 mg/st, pedal 0.01 %, pressure 1 hPa (mbar), temperature 0.1 K,
 * air mass 0.1 mg/st, duty cycle 0.01 %.
 */
class DieselClassifier
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates  raw AxisScanner hits
     * @return MapDef[]
     */
    public function classify(array $candidates): array
    {
        $candidates = array_values(array_filter($candidates, fn (array $c): bool => $this->isPlausible($c)));
        $this->markDurationSets($candidates);

        $maps = [];
        $counters = [];
        foreach ($candidates as $c) {
            $map = $this->toMap($c);
            $base = $map->name;
            $counters[$base] = ($counters[$base] ?? 0) + 1;
            if ($counters[$base] > 1) {
                $map->name = $base.' #'.$counters[$base];
            }
            $maps[] = $map;
        }

        // Re-number the first occurrence when siblings exist so the list reads "#1, #2, ..."
        foreach ($maps as $map) {
            if (($counters[$map->name] ?? 0) > 1) {
                $map->name .= ' #1';
            }
        }

        return $maps;
    }

    /**
     * Drops candidates whose axes contain values that no real Bosch axis uses (typically code).
     *
     * @param  array<string, mixed>  $c
     */
    private function isPlausible(array $c): bool
    {
        foreach (['xRaw', 'yRaw'] as $axis) {
            if ($c[$axis] && max($c[$axis]) > 50000 && min($c[$axis]) < 0x8000) {
                return false;
            }
        }
        $data = $c['data'];

        return count(array_unique($data)) > 1 || count($data) >= 8;
    }

    /**
     * Several large maps with identical dimensions stored back-to-back are almost always the
     * injection duration (energizing time) set.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function markDurationSets(array &$candidates): void
    {
        $count = count($candidates);
        for ($i = 0; $i < $count; $i++) {
            $run = [$i];
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $candidates[$j - 1];
                $b = $candidates[$j];
                $gap = $b['header'] - ($a['address'] + $a['rows'] * $a['cols'] * 2);
                if ($b['rows'] === $a['rows'] && $b['cols'] === $a['cols'] && $gap >= 0 && $gap < 64) {
                    $run[] = $j;
                } else {
                    break;
                }
            }
            $first = $candidates[$i];
            if (count($run) >= 3 && $first['rows'] * $first['cols'] >= 100) {
                foreach ($run as $k) {
                    $candidates[$k]['durationSet'] = true;
                }
                $i = end($run);
            }
        }
    }

    /**
     * Guesses the physical quantity of an axis from its raw values.
     *
     * @param  int[]  $raw
     */
    public static function axisKind(array $raw): string
    {
        if (! $raw) {
            return 'none';
        }
        $min = min($raw);
        $max = max($raw);
        $count = count($raw);

        return match (true) {
            $min >= 2000 && $max <= 4400 && $max - $min >= 100 => 'temp',
            $min >= 500 && $max <= 1100 && $max - $min >= 50 => 'patm',
            $min <= 300 && $max >= 9800 && $max <= 10000 && $count <= 16 => 'pedal',
            $min >= 1000 && $max > 11000 && $max <= 20000 => 'rail',
            $min >= 1000 && $max >= 5000 && $max <= 11000 => 'airmass',
            $max >= 2500 && $max <= 6500 && $min <= 1500 && $count >= 4 => 'rpm|iq',
            $max > 6500 && $max <= 10000 && $min <= 1500 => 'iq',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function toMap(array $c): MapDef
    {
        $rowKind = self::axisKind($c['yRaw']);
        $colKind = self::axisKind($c['xRaw']);
        $data = $c['data'];
        $dMin = min($data);
        $dMax = max($data);
        $isCurve = $c['rows'] === 1;

        // rows are the first stored axis; on Bosch diesels that is normally engine speed
        if ($rowKind === 'rpm|iq') {
            $rowKind = self::axisKind($c['xRaw']) === 'rpm|iq' || in_array(self::axisKind($c['xRaw']), ['pedal', 'airmass', 'patm', 'temp'], true) ? 'rpm' : 'iq';
        }
        if ($colKind === 'rpm|iq') {
            $colKind = $rowKind === 'rpm' || $rowKind === 'rail' ? 'iq' : 'rpm';
        }

        $kinds = [$rowKind, $colKind];
        $has = fn (string $k): bool => in_array($k, $kinds, true);

        [$name, $category, $dataKind, $confidence, $desc] = match (true) {
            ! empty($c['durationSet']) => ['Injection duration', 'Injection', 'duration', 0.7, 'Energizing time vs. fuel quantity. One of a set of identically sized maps.'],
            $isCurve && $colKind === 'rpm' && $c['cols'] >= 8 && $dMax >= 3000 && $dMax <= 9000 => ['Torque limiter (IQ vs rpm)', 'Limiters', 'iq', 0.6, 'Maximum fuel quantity over engine speed.'],
            ! $isCurve && $has('rpm') && $has('pedal') && $dMax <= 9000 => ["Driver's wish", 'Torque / IQ', 'iq', 0.85, 'Requested fuel quantity from pedal position and engine speed.'],
            ! $isCurve && $has('rpm') && $has('airmass') && $dMax <= 9000 && $dMax >= 2000 => ['Smoke limiter (air mass)', 'Limiters', 'iq', 0.75, 'Maximum fuel quantity from measured air mass per stroke.'],
            ! $isCurve && $has('rpm') && $has('patm') && $dMin >= 800 && $dMax <= 3500 => ['Boost pressure limiter', 'Boost', 'pressure', 0.7, 'Maximum boost pressure vs. atmospheric pressure.'],
            ! $isCurve && $has('rpm') && $has('patm') && $dMax <= 9000 && $dMax >= 2000 => ['Torque limiter (atm. pressure)', 'Limiters', 'iq', 0.55, 'Fuel quantity limit vs. engine speed and atmospheric pressure.'],
            ! $isCurve && $has('rpm') && $has('iq') && $dMin >= 800 && $dMax <= 3500 && $dMax - $dMin >= 300 => ['Boost pressure target', 'Boost', 'pressure', 0.75, 'Requested boost pressure (absolute) from engine speed and fuel quantity.'],
            ! $isCurve && $has('rpm') && $has('iq') && $this->rpmMax($c, $rowKind) <= 3200 && $dMin >= 1000 && $dMax <= 10000 => ['EGR air mass target', 'EGR / Air', 'airmass', 0.55, 'Fresh-air mass target for EGR control (part load).'],
            ! $isCurve && $has('rpm') && $has('iq') && $dMin >= 1000 && $dMax <= 9500 => ['Boost actuator duty (N75)', 'Boost', 'duty', 0.5, 'Base duty cycle of the boost pressure actuator.'],
            ! $isCurve && $has('rpm') && $has('iq') && ($dMax > 60000 && $dMin >= 0) => ['Injection timing (SOI)', 'Injection', 'raw', 0.4, 'Start of injection. Signed angle values.'],
            ! $isCurve && $has('rail') && $has('iq') => ['Injection duration', 'Injection', 'duration', 0.6, 'Energizing time vs. rail pressure and fuel quantity.'],
            $has('temp') => [($isCurve ? 'Temperature correction curve' : 'Temperature correction map'), 'Corrections', 'raw', 0.35, 'Correction against coolant / fuel / intake temperature.'],
            $has('patm') => [($isCurve ? 'Atm. pressure correction curve' : 'Atm. pressure correction map'), 'Corrections', 'raw', 0.35, 'Correction against atmospheric pressure.'],
            default => [($isCurve ? 'Curve' : 'Map').' '.$c['rows'].'x'.$c['cols'], 'Unidentified', 'raw', 0.2, 'Potential map found by structure scan.'],
        };

        [$factor, $unit, $decimals, $dtype] = match ($dataKind) {
            'iq' => [0.01, 'mg/st', 2, 'u16'],
            'pressure' => [1.0, 'mbar', 0, 'u16'],
            'duty' => [0.01, '%', 2, 'u16'],
            'airmass' => [0.1, 'mg/st', 1, 'u16'],
            'duration' => [1.0, 'raw', 0, 'u16'],
            default => [1.0, '', 0, $dMax > 0x8000 && $dMin < 0x8000 ? 's16' : 'u16'],
        };

        $map = new MapDef(
            name: $name,
            address: $c['address'],
            rows: $c['rows'],
            cols: $c['cols'],
            dtype: $dtype,
            be: $c['be'],
            factor: $factor,
            unit: $unit,
            category: $category,
            description: $desc.sprintf(' Layout %s, header at 0x%X.', $c['layout'], $c['header']),
            source: 'scan',
            confidence: $confidence,
            decimals: $decimals,
        );
        $map->x = $this->axis($c['xAddr'], $c['xCount'], $colKind, $c['be']);
        if ($c['yAddr'] !== null) {
            $map->y = $this->axis($c['yAddr'], $c['yCount'], $rowKind, $c['be']);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function rpmMax(array $c, string $rowKind): int
    {
        $raw = $rowKind === 'rpm' ? $c['yRaw'] : $c['xRaw'];

        return $raw ? max($raw) : 0;
    }

    private function axis(int $address, int $count, string $kind, bool $be): Axis
    {
        [$factor, $offset, $unit, $name, $decimals, $dtype] = match ($kind) {
            'rpm' => [1.0, 0.0, '1/min', 'Engine speed', 0, 'u16'],
            'iq' => [0.01, 0.0, 'mg/st', 'Fuel quantity', 2, 'u16'],
            'pedal' => [0.01, 0.0, '%', 'Pedal position', 1, 'u16'],
            'patm' => [1.0, 0.0, 'mbar', 'Atm. pressure', 0, 'u16'],
            'temp' => [0.1, -273.1, '°C', 'Temperature', 1, 'u16'],
            'airmass' => [0.1, 0.0, 'mg/st', 'Air mass', 1, 'u16'],
            'rail' => [0.1, 0.0, 'bar', 'Rail pressure', 0, 'u16'],
            default => [1.0, 0.0, '', '', 0, 'u16'],
        };

        return new Axis($count, $address, $dtype, $be, $factor, $offset, $unit, $name, null, $decimals);
    }
}
