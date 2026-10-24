<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\Edc16Checksum;
use App\Ecu\IdStrings;

/**
 * Bosch EDC16 (Freescale MPC5xx PowerPC, big-endian, 1-2 MB).
 */
class Edc16Family extends BoschDieselFamily
{
    public function id(): string
    {
        return 'edc16';
    }

    protected function bigEndian(): bool
    {
        return true;
    }

    protected function checksumClass(): ?string
    {
        return Edc16Checksum::class;
    }

    public function detect(Bin $bin): ?array
    {
        if (! preg_match('/(EDC16[A-Z]?\d{0,2})/', $bin->data, $m)) {
            return null;
        }
        $ids = IdStrings::extract($bin);

        return [
            'score' => 85,
            'family' => 'Bosch EDC16',
            'variant' => $m[1],
            'cpu' => 'MPC5xx (PowerPC)',
            'endian' => 'be',
            'hw' => $ids['hw'],
            'sw' => $ids['sw'],
            'partNumber' => $ids['partNumber'],
            'fuel' => 'Diesel',
            'notes' => ['Map naming is heuristic for EDC16. Verify before use.'],
        ];
    }
}
