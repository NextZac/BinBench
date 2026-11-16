<?php

namespace App\Http\Controllers\Api;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;
use App\Ecu\Patches\PatchService;
use App\Http\Controllers\Controller;
use App\Models\EcuFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Code patches (multimap etc.) for the editor's working buffer. Like fault-path edits, the editor applies
 * the returned writes to its own buffer, so they go through undo, diff and checksum.
 */
class PatchController extends Controller
{
    public function __construct(private PatchService $patches) {}

    /**
     * Patches for this ECU family with their status for the buffer sent (the original file without one).
     */
    public function index(Request $request, EcuFile $file): JsonResponse
    {
        $bin = $this->buffer($request, $file);
        if ($bin instanceof JsonResponse) {
            return $bin;
        }

        return response()->json(['patches' => $this->patches->describe($bin, (string) $file->family_id)]);
    }

    public function install(Request $request, EcuFile $file, string $patch): JsonResponse
    {
        return $this->change($request, $file, $patch, 'available');
    }

    public function uninstall(Request $request, EcuFile $file, string $patch): JsonResponse
    {
        return $this->change($request, $file, $patch, 'installed');
    }

    /**
     * Writes that install ($required = 'available') or remove ($required = 'installed') the patch,
     * plus the map definitions of its tables.
     */
    private function change(Request $request, EcuFile $file, string $id, string $required): JsonResponse
    {
        $patch = $this->patches->find((string) $file->family_id, $id);
        if ($patch === null) {
            return response()->json(['message' => 'Unknown patch for this ECU.'], 404);
        }
        $bin = $this->buffer($request, $file);
        if ($bin instanceof JsonResponse) {
            return $bin;
        }
        $status = $patch->status($bin);
        if ($status['status'] !== $required) {
            return response()->json(['message' => $status['reason'] ?? ($required === 'available' ? 'The patch is already installed.' : 'The patch is not installed.')], 422);
        }

        $writes = $required === 'available' ? $patch->install($bin) : $patch->uninstall($bin);
        // map definitions describe the installed state: read them from the patched buffer on install
        $installed = $bin;
        if ($required === 'available') {
            foreach ($writes as $write) {
                $installed = $installed->withPatch($write['address'], $write['bytes']);
            }
        }

        return response()->json([
            'writes' => PatchService::serializeWrites($writes),
            'maps' => array_map(fn (MapDef $map): array => $map->toArray(), $patch->maps($installed)),
        ]);
    }

    private function buffer(Request $request, EcuFile $file): Bin|JsonResponse
    {
        $content = $request->getContent();
        if ($content === '' || ! str_starts_with((string) $request->header('Content-Type'), 'application/octet-stream')) {
            return $file->bin();
        }
        if (strlen($content) !== $file->size) {
            return response()->json(['message' => 'The buffer size differs from the original file.'], 422);
        }

        return new Bin($content);
    }
}
