<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\Edc15Checksum;
use App\Ecu\IdStrings;

/**
 * Bosch EDC15 (C167, little-endian, 256/512 KB): VAG TDI PD/VP, BMW M47/M57 DDE4, etc.
 */
class Edc15Family extends BoschDieselFamily
{
    public function id(): string
    {
        return 'edc15';
    }

    protected function bigEndian(): bool
    {
        return false;
    }

    protected function checksumClass(): ?string
    {
        return Edc15Checksum::class;
    }

    public function detect(Bin $bin): ?array
    {
        if ($bin->size === 0xC000 && preg_match('/^1037\d{6}$/', $bin->slice(0xBFB0, 10))) {
            return [
                'score' => 70,
                'family' => 'Bosch EDC15',
                'variant' => 'EDC15C (BMW DDE4) calibration only',
                'cpu' => 'Infineon C167',
                'endian' => 'le',
                'sw' => $bin->slice(0xBFB0, 10),
                'fuel' => 'Diesel',
                'notes' => ['48 KB calibration read (flash 0x70000-0x7BFFF). Addresses are relative to the calibration start.'],
            ];
        }
        if (! in_array($bin->size, [0x40000, 0x80000], true) || IdStrings::contains($bin, '/ME7.')) {
            return null;
        }
        $ids = IdStrings::extract($bin);
        $score = 30;
        $variant = 'EDC15';
        $engine = null;

        if (IdStrings::contains($bin, 'EDC15')) {
            $score += 40;
        }
        if ($ids['hw'] && str_starts_with($ids['hw'], '0281')) {
            $score += 25;
        }
        // BMW DDE4 keeps the software number at the end of the calibration area
        if ($bin->size === 0x80000 && preg_match('/^1037\d{6}$/', $bin->slice(0x7FEF0, 10))) {
            $score += 35;
            $variant = 'EDC15C (BMW DDE4)';
            $ids['sw'] = $bin->slice(0x7FEF0, 10);
        }
        if (preg_match('/((?:R4|R5|V6) ?\d[,.]\dl? ?EDC|\d[,.]\dl? ?(?:R4|R5|V6) ?EDC)/i', $bin->data, $m)) {
            $score += 30;
            $engine = trim($m[1]);
        }
        if ($score < 50) {
            return null;
        }

        return [
            'score' => min(100, $score),
            'family' => 'Bosch EDC15',
            'variant' => $variant,
            'cpu' => 'Infineon C167',
            'endian' => 'le',
            'hw' => $ids['hw'],
            'sw' => $ids['sw'],
            'partNumber' => $ids['partNumber'],
            'engine' => $engine,
            'fuel' => 'Diesel',
            'notes' => [],
        ];
    }
}
