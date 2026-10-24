<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Maps\AxisScanner;
use App\Ecu\Maps\DieselClassifier;

/**
 * Shared map finding for Bosch EDC diesel families: structure scan + diesel classifier.
 */
abstract class BoschDieselFamily implements EcuFamily
{
    /** Byte order used for calibration data. */
    abstract protected function bigEndian(): bool;

    /** Checksum module class name, or null. */
    abstract protected function checksumClass(): ?string;

    public function findMaps(Bin $bin, array $ident): array
    {
        [$start, $end] = $this->calibrationRange($bin);
        $candidates = (new AxisScanner($bin, $this->bigEndian(), $start, $end))->scan();

        return (new DieselClassifier)->classify($candidates);
    }

    /**
     * Region scanned for maps. Defaults to the whole file.
     *
     * @return array{0: int, 1: int}
     */
    protected function calibrationRange(Bin $bin): array
    {
        return [0, $bin->size];
    }

    public function checksum(Bin $bin): ?ChecksumModule
    {
        $class = $this->checksumClass();
        if ($class === null || ! class_exists($class)) {
            return null;
        }
        $module = new $class;
        try {
            return $module->supports($bin) ? $module : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
