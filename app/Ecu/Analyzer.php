<?php

namespace App\Ecu;

use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Families\EcuFamily;
use App\Ecu\Families\Edc15Family;
use App\Ecu\Families\Edc16Family;
use App\Ecu\Families\Edc17Family;
use App\Ecu\Families\GenericFamily;
use App\Ecu\Families\Me9Family;
use App\Ecu\Maps\MapDef;

/**
 * Entry point: identifies a dump, finds its maps and reports checksum status.
 */
class Analyzer
{
    /** @var EcuFamily[] */
    private array $families;

    public function __construct()
    {
        $classes = [
            'App\\Ecu\\Families\\Me7Family',
            Me9Family::class,
            Edc15Family::class,
            Edc16Family::class,
            Edc17Family::class,
        ];
        foreach ($classes as $class) {
            if (class_exists($class)) {
                $this->families[] = new $class;
            }
        }
        $this->families[] = new GenericFamily;
    }

    /**
     * @return array{family: EcuFamily, ident: array<string, mixed>}
     */
    public function identify(Bin $bin): array
    {
        $best = null;
        foreach ($this->families as $family) {
            $ident = $family->detect($bin);
            if ($ident !== null && ($best === null || $ident['score'] > $best['ident']['score'])) {
                $best = ['family' => $family, 'ident' => $ident];
            }
        }

        return $best;
    }

    public function family(string $id): ?EcuFamily
    {
        foreach ($this->families as $family) {
            if ($family->id() === $id) {
                return $family;
            }
        }

        return null;
    }

    /**
     * Full analysis. $knownMaps (e.g. from an imported XDF for the same software) take precedence
     * over auto-detected maps at the same address.
     *
     * @param  MapDef[]  $knownMaps
     * @return array{ident: array<string, mixed>, familyId: string, maps: MapDef[], checksums: array<string, mixed>}
     */
    public function analyze(Bin $bin, array $knownMaps = []): array
    {
        ['family' => $family, 'ident' => $ident] = $this->identify($bin);

        $found = $family->findMaps($bin, $ident);
        $byAddress = [];
        foreach ($knownMaps as $map) {
            $byAddress[$map->address] = $map;
        }
        foreach ($found as $map) {
            $byAddress[$map->address] ??= $map;
        }
        ksort($byAddress);

        return [
            'ident' => $ident,
            'familyId' => $family->id(),
            'maps' => array_values($byAddress),
            'checksums' => $this->checksumReport($family->checksum($bin), $bin),
        ];
    }

    /**
     * @return array{module: ?string, canFix: bool, blocks: array<int, array<string, mixed>>, ok: ?bool, error?: string}
     */
    public function checksumReport(?ChecksumModule $module, Bin $bin): array
    {
        if ($module === null) {
            return ['module' => null, 'canFix' => false, 'blocks' => [], 'ok' => null];
        }
        try {
            $blocks = $module->check($bin);
        } catch (\Throwable $e) {
            return ['module' => $module->name(), 'canFix' => false, 'blocks' => [], 'ok' => null, 'error' => $e->getMessage()];
        }

        return [
            'module' => $module->name(),
            'canFix' => $module->canFix(),
            'blocks' => $blocks,
            'ok' => $blocks ? ! in_array(false, array_column($blocks, 'ok'), true) : null,
        ];
    }

    /**
     * Correct checksums with the module of the identified family.
     *
     * @throws \RuntimeException when unsupported or correction fails
     */
    public function fixChecksums(Bin $bin, string $familyId): Bin
    {
        $module = $this->family($familyId)?->checksum($bin);
        if ($module === null || ! $module->canFix()) {
            throw new \RuntimeException('Checksum correction is not available for this ECU.');
        }

        return $module->fix($bin);
    }
}
