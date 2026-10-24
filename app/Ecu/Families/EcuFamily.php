<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Maps\MapDef;

/**
 * One ECU family (ME7, EDC15, ...). The Analyzer asks every family to detect(),
 * picks the highest score, then asks it for maps and its checksum module.
 */
interface EcuFamily
{
    /** Short id, e.g. "me7", "edc15". */
    public function id(): string;

    /**
     * Return null if this is clearly not the family, else an identification array:
     * [
     *   'score' => int 0..100,
     *   'family' => 'Bosch ME7.5', 'variant' => '...', 'cpu' => 'C167', 'endian' => 'le'|'be',
     *   'hw' => '0261207440', 'sw' => '1037363908', 'partNumber' => '06A906032HN', 'swVersion' => '0003',
     *   'engine' => '1.8L R4/5VT', 'vehicle' => 'Audi A3', 'notes' => [string],
     * ]  (only score/family are required)
     */
    public function detect(Bin $bin): ?array;

    /** @return MapDef[] */
    public function findMaps(Bin $bin, array $ident): array;

    public function checksum(Bin $bin): ?ChecksumModule;
}
