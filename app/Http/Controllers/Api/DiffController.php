<?php

namespace App\Http\Controllers\Api;

use App\Ecu\Analyzer;
use App\Ecu\Diff\BinDiff;
use App\Ecu\Diff\TuneReview;
use App\Ecu\Dyno\DynoEstimator;
use App\Ecu\Maps\MapDef;
use App\Http\Controllers\Controller;
use App\Http\Requests\DynoRequest;
use App\Models\EcuFile;
use Illuminate\Http\JsonResponse;

class DiffController extends Controller
{
    /**
     * Map-level diff of a modified buffer against the original file, with a heuristic review
     * (verdict tag and findings) and the estimated dyno difference.
     */
    public function __invoke(DynoRequest $request, EcuFile $file): JsonResponse
    {
        $original = $file->bin();
        $modified = $request->buffer() ?? $original;
        $maps = array_map(fn (array $m): MapDef => MapDef::fromArray($m), $file->maps ?? []);
        $analyzer = new Analyzer;
        $checksum = $analyzer->checksumReport($analyzer->family((string) $file->family_id)?->checksum($modified), $modified);
        $spec = $request->engineSpec();
        $overrides = $request->roleOverrides();

        $diff = BinDiff::compare($original, $modified, $maps);
        $dyno = (new DynoEstimator)->estimate($maps, $spec, $original, $modified, $overrides);

        return response()->json([
            ...$diff,
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
