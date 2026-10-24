<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Checksum\Me7Checksum;
use App\Ecu\Families\Me7\LookupScanner;
use App\Ecu\Families\Me7\MapCatalog;
use App\Ecu\Families\Me7\Me7Ident;

/**
 * Bosch ME7.x (ME7.1, ME7.1.1, ME7.5 ...) for VAG: Infineon C167, little-endian, 512 KB / 1 MB flash.
 * File offset X = CPU address 0x800000 + X.
 */
class Me7Family implements EcuFamily
{
    /** Also return every harvested-but-unidentified table (low confidence, category Other). */
    public bool $includeUnnamed = true;

    public function id(): string
    {
        return 'me7';
    }

    public function detect(Bin $bin): ?array
    {
        if (! in_array($bin->size, [0x80000, 0x100000], true)) {
            return null;
        }
        $id = Me7Ident::read($bin);
        $marks = Me7Ident::structure($bin);

        $score = 0;
        $score += isset($id['variant']) ? 50 : 0;
        $score += isset($id['hw']) ? 15 : 0;                    // 0261 = Bosch gasoline ECU
        $score += isset($id['sw']) ? 5 : 0;
        $score += isset($id['partNumber']) ? 10 : 0;
        $score += count($marks) * 7;
        // Without an ME7 string or a 0261 hardware number this is most likely another C167 ECU (EDC15/16 ...).
        if (! isset($id['variant']) && ! isset($id['hw'])) {
            return $marks ? ['score' => min(15, $score), 'family' => 'Bosch ME7 (unconfirmed)', 'cpu' => 'C167', 'endian' => 'le', 'notes' => ['C167 structure only, no ME7 ident found']] : null;
        }
        if ($score < 20) {
            return null;
        }

        $variant = $id['variant'] ?? 'ME7';
        $notes = $marks;
        if (isset($id['project'])) {
            $notes[] = 'Project '.$id['project'].(empty($id['dataset']) ? '' : ' / '.$id['dataset']);
        }

        return array_filter([
            'score' => min(100, $score),
            'family' => 'Bosch '.$variant,
            'variant' => $variant,
            'cpu' => 'C167',
            'endian' => 'le',
            'hw' => $id['hw'] ?? null,
            'sw' => $id['sw'] ?? null,
            'partNumber' => $id['partNumber'] ?? null,
            'swVersion' => $id['swVersion'] ?? null,
            'engine' => $id['engine'] ?? null,
            'notes' => $notes,
        ], fn ($v) => $v !== null);
    }

    public function findMaps(Bin $bin, array $ident): array
    {
        $scan = (new LookupScanner($bin))->scan();

        return (new MapCatalog($bin, $scan))->build($this->includeUnnamed);
    }

    public function checksum(Bin $bin): ?ChecksumModule
    {
        return class_exists(Me7Checksum::class) ? new Me7Checksum : null;
    }
}
