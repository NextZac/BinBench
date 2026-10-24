<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\IdStrings;
use App\Ecu\Maps\AxisScanner;
use App\Ecu\Maps\DieselClassifier;

/**
 * Fallback for unknown files: structure scan in both byte orders, keeping the richer result.
 */
class GenericFamily implements EcuFamily
{
    public function id(): string
    {
        return 'generic';
    }

    public function detect(Bin $bin): ?array
    {
        $ids = IdStrings::extract($bin);

        return [
            'score' => 1,
            'family' => 'Unknown',
            'variant' => null,
            'hw' => $ids['hw'],
            'sw' => $ids['sw'],
            'partNumber' => $ids['partNumber'],
            'notes' => ['ECU not recognised. Maps below come from a generic structure scan.'],
        ];
    }

    public function findMaps(Bin $bin, array $ident): array
    {
        $le = (new AxisScanner($bin, false))->scan();
        $be = (new AxisScanner($bin, true))->scan();
        $pick = count($be) > count($le) * 1.3 ? $be : $le;

        $maps = (new DieselClassifier)->classify($pick);
        foreach ($maps as $map) {
            $map->confidence = min($map->confidence, 0.3);
        }

        return $maps;
    }

    public function checksum(Bin $bin): ?ChecksumModule
    {
        return null;
    }
}
