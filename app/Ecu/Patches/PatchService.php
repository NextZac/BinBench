<?php

namespace App\Ecu\Patches;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;

/**
 * The code patches known for an ECU family and what the editor needs to offer them.
 */
class PatchService
{
    /** @var array<int, class-string<CodePatch>> */
    private const PATCHES = [
        Dde4CruiseSwitchMultimap::class,
        Dde4CruiseButtonsMultimap::class,
    ];

    /**
     * @return CodePatch[]
     */
    public function forFamily(string $familyId): array
    {
        $patches = array_map(fn (string $class): CodePatch => new $class, self::PATCHES);

        return array_values(array_filter($patches, fn (CodePatch $patch): bool => $patch->familyId() === $familyId));
    }

    public function find(string $familyId, string $id): ?CodePatch
    {
        foreach ($this->forFamily($familyId) as $patch) {
            if ($patch->id() === $id) {
                return $patch;
            }
        }

        return null;
    }

    /**
     * Every patch of the family with its status for this buffer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(Bin $bin, string $familyId): array
    {
        return array_map(fn (CodePatch $patch): array => [
            'id' => $patch->id(),
            ...$patch->describe(),
            ...$patch->status($bin),
        ], $this->forFamily($familyId));
    }

    /**
     * Map definitions of every patch installed in this buffer.
     *
     * @return MapDef[]
     */
    public function installedMaps(Bin $bin, string $familyId): array
    {
        $maps = [];
        foreach ($this->forFamily($familyId) as $patch) {
            if ($patch->status($bin)['status'] === 'installed') {
                array_push($maps, ...$patch->maps($bin));
            }
        }

        return $maps;
    }

    /**
     * Writes in the form the editor applies: address and hex bytes.
     *
     * @param  array<int, array{address: int, bytes: string}>  $writes
     * @return array<int, array{address: int, hex: string}>
     */
    public static function serializeWrites(array $writes): array
    {
        return array_map(fn (array $write): array => ['address' => $write['address'], 'hex' => bin2hex($write['bytes'])], $writes);
    }
}
