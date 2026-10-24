<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\Edc17Checksum;
use App\Ecu\IdStrings;

/**
 * Bosch EDC17 / MD1 diesel (Infineon TriCore, little-endian, 1.5-4 MB).
 */
class Edc17Family extends BoschDieselFamily
{
    public function id(): string
    {
        return 'edc17';
    }

    protected function bigEndian(): bool
    {
        return false;
    }

    protected function checksumClass(): ?string
    {
        return Edc17Checksum::class;
    }

    public function detect(Bin $bin): ?array
    {
        if (! preg_match('/(EDC17[A-Z]{1,2}\d{1,2}|MD1C[A-Z]\d{1,3})/', $bin->data, $m)) {
            return null;
        }
        $ids = IdStrings::extract($bin);

        return [
            'score' => 85,
            'family' => str_starts_with($m[1], 'MD1') ? 'Bosch MD1' : 'Bosch EDC17',
            'variant' => $m[1],
            'cpu' => 'Infineon TriCore',
            'endian' => 'le',
            'hw' => $ids['hw'],
            'sw' => $ids['sw'],
            'partNumber' => $ids['partNumber'],
            'fuel' => 'Diesel',
            'notes' => ['Map naming is heuristic for EDC17. Verify before use. Flashing EDC17 usually needs a tool that handles the RSA signature.'],
        ];
    }
}
