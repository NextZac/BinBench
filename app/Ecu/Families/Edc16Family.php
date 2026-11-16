<?php

namespace App\Ecu\Families;

use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Checksum\Edc16cBmwChecksum;
use App\Ecu\Checksum\Edc16Checksum;
use App\Ecu\Families\Edc16\BmwEdc16cLayout;
use App\Ecu\Families\Edc16\Dde5MapCatalog;
use App\Ecu\IdStrings;
use App\Ecu\Maps\AxisScanner;
use App\Ecu\Maps\DieselClassifier;
use App\Ecu\Maps\MapDef;

/**
 * Bosch EDC16 (Freescale MPC5xx PowerPC, big-endian, 1-2 MB).
 *
 * BMW EDC16C (DDE5) full reads of 1.5 MB get their own address map: maps are only taken from the
 * calibration and only where the code references them, and known software gets named tables.
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
        $variant = $m[1];
        $notes = ['Map naming is heuristic for EDC16. Verify before use.'];

        if (BmwEdc16cLayout::matches($bin)) {
            $variant = 'EDC16C (BMW DDE5)';
            $notes = ['Full read: 448 KB internal + 1 MB external flash. Addresses are file offsets; the external flash is CPU 0x800000 + (offset - 0x70000).'];
            if (Dde5MapCatalog::supports($ids['sw'])) {
                $notes[] = 'Main tables of this software are named from reverse engineering (use sites verified, units inferred). Other maps are referenced by the code but named heuristically.';
            }
            $notes[] = 'Code changes need a full BDM/boot-mode write: OBD programming checks a signature that the checksum does not cover.';
        }

        return [
            'score' => 85,
            'family' => 'Bosch EDC16',
            'variant' => $variant,
            'cpu' => 'MPC5xx (PowerPC)',
            'endian' => 'be',
            'hw' => $ids['hw'],
            'sw' => $ids['sw'],
            'partNumber' => $ids['partNumber'],
            'fuel' => 'Diesel',
            'notes' => $notes,
        ];
    }

    public function findMaps(Bin $bin, array $ident): array
    {
        if (! BmwEdc16cLayout::matches($bin)) {
            return parent::findMaps($bin, $ident);
        }

        [$start, $end] = BmwEdc16cLayout::calibrationRange();
        $referenced = BmwEdc16cLayout::referencedAddresses($bin);
        $candidates = array_values(array_filter(
            (new AxisScanner($bin, true, $start, $end))->scan(),
            fn (array $candidate): bool => isset($referenced[$candidate['header']]),
        ));

        $named = Dde5MapCatalog::maps($bin, $ident['sw'] ?? null);
        $namedAddresses = array_flip(array_map(fn (MapDef $map): int => $map->address, $named));
        $scanned = array_filter(
            (new DieselClassifier($this->durationSets()))->classify($candidates),
            fn (MapDef $map): bool => ! isset($namedAddresses[$map->address]),
        );
        // next to verified tables, a guessed "Rail pressure target" must not look like the real one
        if ($named) {
            foreach ($scanned as $map) {
                if ($map->category !== 'Unidentified') {
                    $map->name .= ' (heuristic)';
                    $map->confidence = min($map->confidence, 0.5);
                }
            }
        }

        $maps = [...$named, ...$scanned];
        usort($maps, fn (MapDef $a, MapDef $b): int => $a->address <=> $b->address);

        return $maps;
    }

    public function checksum(Bin $bin): ?ChecksumModule
    {
        foreach ([Edc16cBmwChecksum::class, Edc16Checksum::class] as $class) {
            $module = new $class;
            try {
                if ($module->supports($bin)) {
                    return $module;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
