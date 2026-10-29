<?php

namespace App\Http\Controllers\Api;

use App\Ecu\Analyzer;
use App\Ecu\Diff\BinDiff;
use App\Ecu\Diff\TuneReview;
use App\Ecu\Dyno\DynoEstimator;
use App\Ecu\Maps\MapDef;
use App\Http\Controllers\Controller;
use App\Http\Requests\DiffRequest;
use App\Models\EcuFile;
use Illuminate\Http\JsonResponse;

class DiffController extends Controller
{
    /**
     * Map-level diff between two dumps (by default the original and the editor's buffer), with a
     * heuristic review (verdict tag and findings) and the estimated dyno difference. Either side
     * can be the original, a saved version or another file of the same size.
     */
    public function __invoke(DiffRequest $request, EcuFile $file): JsonResponse
    {
        $base = $request->base();
        $target = $request->target();
        $original = $base['bin'];
        $modified = $target['bin'];
        $maps = array_map(fn (array $m): MapDef => MapDef::fromArray($m), $file->maps ?? []);
        $analyzer = new Analyzer;
        $checksum = $analyzer->checksumReport($analyzer->family((string) $file->family_id)?->checksum($modified), $modified);
        $spec = $request->engineSpec();
        $overrides = $request->roleOverrides();

        $diff = BinDiff::compare($original, $modified, $maps);
        $dyno = (new DynoEstimator)->estimate($maps, $spec, $original, $modified, $overrides);

        return response()->json([
            ...$diff,
            'base' => ['label' => $base['label'], 'sw' => $base['sw']],
            'target' => ['label' => $target['label'], 'sw' => $target['sw']],
            'swMismatch' => $base['sw'] !== null && $target['sw'] !== null && $base['sw'] !== $target['sw'],
            'review' => (new TuneReview)->review($maps, $spec, $original, $modified, $diff, $checksum, $overrides),
            'checksum' => $checksum,
            'dyno' => $dyno['available'] ? [
                'calibrated' => $dyno['calibrated'],
                'stock' => ['power' => $dyno['stock']['peakPower'], 'torque' => $dyno['stock']['peakTorque']],
                'modified' => ['power' => $dyno['modified']['peakPower'], 'torque' => $dyno['modified']['peakTorque']],
            ] : null,
        ]);
    }
}
