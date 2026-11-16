<?php

namespace App\Ecu\Dtc;

use App\Ecu\Bin;

/**
 * Picks the fault-table locator for an ECU family and turns its result into what the editor needs,
 * including which paths are emissions-related and therefore protected.
 */
class DtcService
{
    /** @var array<string, class-string<DtcLocator>> family id => locator */
    private const LOCATORS = [
        'me7' => Me7DtcLocator::class,
        'me9' => Me9DtcLocator::class,
        'edc15' => Edc15DtcLocator::class,
    ];

    /** Path names (where the locator knows them) that mark emissions monitoring regardless of the code. */
    private const EMISSION_NAMES = '/\b(EGR|AGR|catalyst|NOx|DPF|particulate|lambda|oxygen|O2 sensor|secondary air|EVAP|misfire)\b/i';

    /** Families whose P1xxx codes follow VAG numbering. */
    private const VAG_FAMILIES = ['me7'];

    public function locator(string $familyId): ?DtcLocator
    {
        $class = self::LOCATORS[$familyId] ?? null;

        return $class !== null && class_exists($class) ? new $class : null;
    }

    public function table(Bin $bin, string $familyId): ?DtcTable
    {
        try {
            return $this->locator($familyId)?->locate($bin);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(?DtcTable $table, string $familyId = ''): array
    {
        if ($table === null) {
            return ['available' => false, 'paths' => [], 'notes' => [], 'fieldHelp' => []];
        }

        $paths = [];
        foreach ($table->paths as $path) {
            // 'value' is the stored number, 'code' its text form (P0301)
            $vag = in_array($familyId, self::VAG_FAMILIES, true);
            $codes = array_map(fn (array $c): array => ['value' => $c['code'], ...$c, ...DtcCatalog::describe($c['code'], $vag)], $path->codes);
            $reason = $this->protectionReason($path, $familyId);
            $paths[] = [
                ...$path->toArray(),
                'codes' => $codes,
                'protected' => $reason !== null,
                'protectedReason' => $reason,
            ];
        }

        return [
            'available' => true,
            'codeSize' => $table->codeSize,
            'bigEndian' => $table->bigEndian,
            'notes' => $table->notes,
            'fieldHelp' => $table->fieldHelp,
            'paths' => $paths,
        ];
    }

    /**
     * Emissions-related P-codes whose fault path was changed between two dumps.
     *
     * @return string[]
     */
    public function protectedChanges(Bin $original, Bin $modified, ?DtcTable $table, string $familyId = ''): array
    {
        if ($table === null || $original->size !== $modified->size) {
            return [];
        }

        $changed = [];
        foreach ($table->paths as $path) {
            if ($this->protectionReason($path, $familyId) === null) {
                continue;
            }
            $emission = $path->pcodes() ?: [$path->index];
            $ranges = [
                ...array_map(fn (array $c): array => [$c['address'], $table->codeSize], $path->codes),
                ...array_map(fn (array $f): array => [$f['address'], $f['size']], $path->fields),
            ];
            foreach ($ranges as [$address, $size]) {
                if ($original->slice($address, $size) !== $modified->slice($address, $size)) {
                    array_push($changed, ...array_map(fn (int $code): string => $path->pcodes() ? DtcCatalog::format($code) : "path {$code}", $emission));
                    break;
                }
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * Why a fault path belongs to emissions monitoring (by its codes or its name), or null.
     */
    public function protectionReason(FaultPath $path, string $familyId = ''): ?string
    {
        $vag = in_array($familyId, self::VAG_FAMILIES, true);
        foreach ($path->pcodes() as $code) {
            $reason = DtcCatalog::emissionReason($code, $vag);
            if ($reason !== null) {
                return $reason;
            }
        }
        if ($path->name !== null && preg_match(self::EMISSION_NAMES, $path->name, $m)) {
            return 'Emissions system ('.$m[1].')';
        }

        return null;
    }
}
