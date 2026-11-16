<?php

namespace App\Ecu\Export;

use App\Ecu\Analyzer;
use App\Ecu\Bin;
use App\Ecu\Diff\BinDiff;
use App\Ecu\Maps\MapDef;

/**
 * The only way a modified buffer becomes an exportable file.
 *
 * A file leaves as "verified" when every checksum block validates after recalculation. Anything
 * else (no checksum module for the ECU, blocks BinBench cannot correct, correction switched off)
 * is "unverified": it is blocked unless the user explicitly acknowledges it, and the reason is
 * recorded with the version.
 *
 * The gate never changes bytes on its own account. The only bytes that may differ between the
 * buffer it receives and the file it returns are those written by the checksum module, and they
 * are listed in the result.
 */
class ExportGate
{
    public const VERIFIED = 'verified';

    public const UNVERIFIED = 'unverified';

    /** Correction ranges listed individually in the change record; beyond this only the totals. */
    private const MAX_LISTED_RANGES = 200;

    public function __construct(private Analyzer $analyzer = new Analyzer) {}

    /**
     * @param  MapDef[]  $maps  definitions of the file, for the change record
     * @param  bool  $correct  let the checksum module correct invalid blocks
     * @param  bool  $acknowledgeUnverified  the user accepts a file BinBench cannot verify
     * @return array{bin: Bin, state: string, corrected: bool, reason: ?string, checksums: array<string, mixed>, changes: array<string, mixed>}
     *
     * @throws ExportBlocked when the result would be unverified and that was not acknowledged
     * @throws \InvalidArgumentException when the buffer cannot belong to the original
     */
    public function prepare(Bin $original, Bin $modified, string $familyId, array $maps, bool $correct = true, bool $acknowledgeUnverified = false): array
    {
        if ($modified->size !== $original->size) {
            throw new \InvalidArgumentException('Modified file size differs from the original.');
        }

        $family = $this->analyzer->family($familyId);
        $final = $modified;
        $corrected = false;
        $reason = null;

        $module = $family?->checksum($modified);
        $report = $this->analyzer->checksumReport($module, $modified);

        if ($module === null) {
            $reason = 'BinBench has no checksum module for this ECU, so the checksums were neither checked nor corrected.';
        } elseif ($report['ok'] !== true) {
            if ($report['ok'] === null && ! isset($report['error'])) {
                $reason = "{$module->name()} found no checksum blocks in this file, so nothing was verified.";
            } elseif (! $module->canFix()) {
                $reason = isset($report['error'])
                    ? "The checksums could not be checked ({$report['error']})."
                    : "{$module->name()} can only check, not correct, and ".$this->invalidBlocks($report).' invalid.';
            } elseif (! $correct) {
                $reason = 'Checksum correction was switched off and '.$this->invalidBlocks($report).' invalid.';
            } else {
                try {
                    $fixed = $module->fix($modified);
                    $after = $this->analyzer->checksumReport($family->checksum($fixed), $fixed);
                    if ($fixed->size !== $modified->size || $after['ok'] !== true) {
                        $reason = 'Checksum correction ran but the result does not validate.';
                    } else {
                        $final = $fixed;
                        $report = $after;
                        $corrected = true;
                    }
                } catch (\Throwable $e) {
                    $reason = 'Checksum correction failed: '.$e->getMessage();
                }
            }
        }

        if ($reason !== null && ! $acknowledgeUnverified) {
            throw new ExportBlocked($reason, $report);
        }

        return [
            'bin' => $final,
            'state' => $reason === null ? self::VERIFIED : self::UNVERIFIED,
            'corrected' => $corrected,
            'reason' => $reason,
            'checksums' => $report,
            'changes' => $this->changes($original, $modified, $final, $maps),
        ];
    }

    /**
     * What differs from the original, computed from the bytes (never taken from the client), and
     * which bytes the checksum correction wrote.
     *
     * @param  MapDef[]  $maps
     * @return array<string, mixed>
     */
    private function changes(Bin $original, Bin $modified, Bin $final, array $maps): array
    {
        $diff = BinDiff::compare($original, $modified, $maps);
        $correction = BinDiff::changedRuns($modified, $final);

        return [
            'changedBytes' => $diff['changedBytes'],
            'maps' => array_map(fn (array $m): array => [
                'id' => $m['id'],
                'name' => $m['name'],
                'category' => $m['category'],
                'changedCells' => $m['changedCells'],
                'meanChangePct' => $m['meanChangePct'],
                'axisChanged' => $m['axisChanged'],
            ], $diff['maps']),
            'unmapped' => $diff['unmapped'],
            'correction' => [
                'bytes' => array_sum(array_map(fn (array $r): int => $r[1] - $r[0], $correction)),
                'ranges' => array_map(fn (array $r): array => ['start' => $r[0], 'end' => $r[1]], array_slice($correction, 0, self::MAX_LISTED_RANGES)),
                'rangeCount' => count($correction),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function invalidBlocks(array $report): string
    {
        $bad = count(array_filter($report['blocks'] ?? [], fn (array $b): bool => ! $b['ok']));

        return $bad === 1 ? '1 block is' : "{$bad} blocks are";
    }
}
