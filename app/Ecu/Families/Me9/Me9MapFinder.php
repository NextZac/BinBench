<?php

namespace App\Ecu\Families\Me9;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;
use Throwable;

/**
 * Map finder for Bosch ME9.0 petrol ECUs (Freescale MPC5xx, big-endian), e.g. Ford Focus RS mk2
 * / Volvo 2.5T five-cylinder.
 *
 * Tables are harvested from the code (calls into the calibration lookup library) and named by
 * how they are used; nothing depends on fixed addresses. Files that do not contain the ME9
 * lookup library give an empty list.
 */
class Me9MapFinder
{
    /** Also return every harvested-but-unidentified table (low confidence, category Other). */
    public bool $includeUnnamed = true;

    public ?Me9LookupScanner $scanner = null;

    /** @param  array<string, array>  $fingerprints  lookup-routine fingerprints, see Me9Routines */
    public function __construct(private array $fingerprints = Me9Routines::TABLE) {}

    /** @return MapDef[] */
    public function find(Bin $bin): array
    {
        try {
            $this->scanner = new Me9LookupScanner($bin, $this->fingerprints);
            if (! $this->scanner->scan() || ! $this->scanner->tables) {
                return [];
            }

            return (new Me9MapCatalog($bin, $this->scanner))->build($this->includeUnnamed);
        } catch (Throwable) {
            return [];
        }
    }
}
