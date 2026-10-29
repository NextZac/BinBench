<?php

namespace App\Ecu\Diff;

use App\Ecu\Bin;
use App\Ecu\Maps\Axis;
use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapValues;
use App\Ecu\Maps\MapWriter;

/**
 * Map-level comparison of two dumps of the same size: which maps changed and how, which axes
 * moved, and which changed bytes belong to no known map.
 */
class BinDiff
{
    /** Changed byte runs closer than this are reported as one range. */
    private const MERGE_GAP = 16;

    /** A cell counts as a spike when it sits this far (share of the map's range) from its neighbours. */
    private const SPIKE_THRESHOLD = 0.35;

    /**
     * @param  MapDef[]  $maps
     * @return array{changedBytes: int, maps: array<int, array<string, mixed>>, unmapped: array<int, array{start: int, end: int, length: int}>}
     */
    public static function compare(Bin $original, Bin $modified, array $maps): array
    {
        $runs = self::changedRuns($original, $modified);
        $changedBytes = array_sum(array_map(fn (array $r): int => $r[1] - $r[0], $runs));

        $covered = [];
        $changedMaps = [];
        foreach ($maps as $map) {
            $data = [$map->address, $map->address + $map->byteSize()];
            $axes = array_values(array_filter([self::axisRange($map->x), self::axisRange($map->y)]));
            array_push($covered, $data, ...$axes);

            $dataChanged = self::touches($runs, $data);
            $axisChanged = (bool) array_filter($axes, fn (array $range): bool => self::touches($runs, $range));
            if (($dataChanged || $axisChanged) && $data[1] <= $original->size) {
                $changedMaps[] = self::mapStats($map, $original, $modified, $axisChanged);
            }
        }

        return [
            'changedBytes' => $changedBytes,
            'maps' => $changedMaps,
            'unmapped' => self::unmapped($runs, $covered),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapStats(MapDef $map, Bin $original, Bin $modified, bool $axisChanged): array
    {
        $before = new MapValues($map, $original);
        $after = new MapValues($map, $modified);

        $changed = 0;
        $pcts = [];
        $maxIncrease = 0.0;
        $maxDecrease = 0.0;
        $clamped = false;
        $limits = MapWriter::limits($map->dtype);
        foreach ($after->grid as $r => $row) {
            foreach ($row as $c => $new) {
                $old = $before->grid[$r][$c];
                if (abs($new - $old) < 1e-9) {
                    continue;
                }
                $changed++;
                $maxIncrease = max($maxIncrease, $new - $old);
                $maxDecrease = min($maxDecrease, $new - $old);
                if ($old != 0) {
                    $pcts[] = ($new - $old) / abs($old) * 100;
                }
                if ($limits !== null) {
                    // pinned at the top of the storage type (or the bottom of a signed one): typical of a scaling or typing mistake
                    $raw = round(($new - $map->offset) / ($map->factor ?: 1));
                    $clamped = $clamped || $raw >= $limits[1] || ($limits[0] < 0 && $raw <= $limits[0]);
                }
            }
        }

        $oldCells = array_merge(...$before->grid);
        $newCells = array_merge(...$after->grid);
        $spikeBefore = self::roughness($before->grid);
        $spikeAfter = self::roughness($after->grid);
        $spike = $spikeAfter['score'] > self::SPIKE_THRESHOLD && $spikeAfter['score'] > 2 * $spikeBefore['score'] + 0.05
            && abs($after->grid[$spikeAfter['row']][$spikeAfter['col']] - $before->grid[$spikeAfter['row']][$spikeAfter['col']]) > 1e-9
            ? ['row' => $spikeAfter['row'], 'col' => $spikeAfter['col']] : null;

        return [
            'id' => $map->id,
            'name' => $map->name,
            'category' => $map->category,
            'unit' => $map->unit,
            'kind' => $map->kind(),
            'cells' => $map->rows * $map->cols,
            'changedCells' => $changed,
            'before' => ['min' => min($oldCells), 'max' => max($oldCells)],
            'after' => ['min' => min($newCells), 'max' => max($newCells)],
            'meanChangePct' => $pcts ? round(array_sum($pcts) / count($pcts), 1) : null,
            'maxIncrease' => round($maxIncrease, 4),
            'maxDecrease' => round($maxDecrease, 4),
            'axisChanged' => $axisChanged,
            'clamped' => $clamped,
            'spike' => $spike,
        ];
    }

    /**
     * Largest distance of a cell from the mean of its direct neighbours, as a share of the map's range.
     *
     * @param  float[][]  $grid
     * @return array{score: float, row: int, col: int}
     */
    private static function roughness(array $grid): array
    {
        $cells = array_merge(...$grid);
        $range = max($cells) - min($cells);
        $best = ['score' => 0.0, 'row' => 0, 'col' => 0];
        if ($range <= 0 || count($cells) < 3) {
            return $best;
        }
        foreach ($grid as $r => $row) {
            foreach ($row as $c => $v) {
                $neighbours = array_filter([
                    $grid[$r - 1][$c] ?? null, $grid[$r + 1][$c] ?? null, $row[$c - 1] ?? null, $row[$c + 1] ?? null,
                ], fn (?float $n): bool => $n !== null);
                // only cells with neighbours on both sides of one axis: edges legitimately bend
                $inner = (isset($grid[$r - 1][$c], $grid[$r + 1][$c])) || (isset($row[$c - 1], $row[$c + 1]));
                if (! $inner) {
                    continue;
                }
                $score = abs($v - array_sum($neighbours) / count($neighbours)) / $range;
                if ($score > $best['score']) {
                    $best = ['score' => $score, 'row' => $r, 'col' => $c];
                }
            }
        }

        return $best;
    }

    /**
     * Byte ranges [start, end) where the dumps differ.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function changedRuns(Bin $a, Bin $b): array
    {
        preg_match_all('/[^\x00]+/', $a->data ^ $b->data, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $hit): array => [$hit[1], $hit[1] + strlen($hit[0])], $m[0]);
    }

    /**
     * @return ?array{0: int, 1: int}
     */
    private static function axisRange(?Axis $axis): ?array
    {
        if ($axis === null || $axis->address === null || $axis->values !== null) {
            return null;
        }

        return [$axis->address, $axis->address + $axis->count * Bin::typeSize($axis->dtype)];
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $runs
     * @param  array{0: int, 1: int}  $range
     */
    private static function touches(array $runs, array $range): bool
    {
        foreach ($runs as [$start, $end]) {
            if ($start < $range[1] && $end > $range[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Changed bytes not covered by any map or axis, merged into ranges.
     *
     * @param  array<int, array{0: int, 1: int}>  $runs
     * @param  array<int, array{0: int, 1: int}>  $covered
     * @return array<int, array{start: int, end: int, length: int}>
     */
    private static function unmapped(array $runs, array $covered): array
    {
        usort($covered, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $free = [];
        foreach ($runs as [$start, $end]) {
            $pieces = [[$start, $end]];
            foreach ($covered as [$cs, $ce]) {
                if ($ce <= $start || $cs >= $end) {
                    continue;
                }
                $next = [];
                foreach ($pieces as [$ps, $pe]) {
                    if ($ps < $cs) {
                        $next[] = [$ps, min($pe, $cs)];
                    }
                    if ($pe > $ce) {
                        $next[] = [max($ps, $ce), $pe];
                    }
                }
                $pieces = $next;
            }
            array_push($free, ...$pieces);
        }

        $merged = [];
        foreach ($free as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start - $merged[$last]['end'] <= self::MERGE_GAP) {
                $merged[$last]['end'] = $end;
                $merged[$last]['length'] = $end - $merged[$last]['start'];
            } else {
                $merged[] = ['start' => $start, 'end' => $end, 'length' => $end - $start];
            }
        }

        return $merged;
    }
}
