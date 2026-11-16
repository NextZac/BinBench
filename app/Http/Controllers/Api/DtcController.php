<?php

namespace App\Http\Controllers\Api;

use App\Ecu\Dtc\DtcService;
use App\Http\Controllers\Controller;
use App\Models\EcuFile;
use Illuminate\Http\JsonResponse;

class DtcController extends Controller
{
    /**
     * Fault paths of the original file with their codes, settings and protection status. The editor
     * applies changes to its own buffer, so they go through undo, diff and checksum like map edits.
     */
    public function __invoke(EcuFile $file, DtcService $dtc): JsonResponse
    {
        return response()->json($dtc->describe($dtc->table($file->bin(), (string) $file->family_id), (string) $file->family_id));
    }
}
