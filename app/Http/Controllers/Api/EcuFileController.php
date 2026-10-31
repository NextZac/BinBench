<?php

namespace App\Http\Controllers\Api;

use App\Ecu\Analyzer;
use App\Ecu\Bin;
use App\Ecu\Dtc\DtcService;
use App\Ecu\Dyno\DynoEstimator;
use App\Ecu\Maps\MapDef;
use App\Ecu\Xdf\XdfExporter;
use App\Ecu\Xdf\XdfImporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\DynoRequest;
use App\Models\EcuFile;
use App\Models\EcuFileVersion;
use App\Models\MapDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EcuFileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->ecuFiles()->latest()->get(['id', 'name', 'original_filename', 'size', 'family', 'family_id', 'sw', 'map_count', 'checksums', 'created_at'])
                ->map(fn (EcuFile $f): array => [
                    ...$f->only(['id', 'name', 'original_filename', 'size', 'family', 'family_id', 'sw', 'map_count', 'created_at']),
                    'checksumOk' => $f->checksums['ok'] ?? null,
                ])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:8192']]);
        $upload = $request->file('file');
        $data = $upload->getContent();
        if (strlen($data) < 1024) {
            return response()->json(['message' => 'File is too small to be an ECU dump.'], 422);
        }

        $path = 'bins/'.Str::uuid().'.bin';
        Storage::disk('local')->put($path, $data);

        $file = $request->user()->ecuFiles()->create([
            'name' => pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $upload->getClientOriginalName(),
            'size' => strlen($data),
            'sha1' => sha1($data),
            'path' => $path,
        ])->analyze();

        return response()->json($this->payload($file), 201);
    }

    public function show(EcuFile $file): JsonResponse
    {
        return response()->json($this->payload($file));
    }

    public function update(Request $request, EcuFile $file): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'maps' => ['sometimes', 'array'],
        ]);
        $file->fill($validated);
        if (isset($validated['maps'])) {
            $file->map_count = count($validated['maps']);
        }
        $file->save();

        return response()->json($this->payload($file));
    }

    public function reanalyze(EcuFile $file): JsonResponse
    {
        return response()->json($this->payload($file->analyze()));
    }

    public function destroy(EcuFile $file): Response
    {
        Storage::disk('local')->delete([$file->path, ...$file->versions->pluck('path')]);
        $file->delete();

        return response()->noContent();
    }

    public function raw(EcuFile $file): Response
    {
        return response(Storage::disk('local')->get($file->path), 200, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * Checksum status of a (modified) buffer sent by the editor, without storing it.
     */
    public function checksum(Request $request, EcuFile $file): JsonResponse
    {
        $bin = new Bin($request->getContent());
        $analyzer = new Analyzer;

        return response()->json($analyzer->checksumReport($analyzer->family($file->family_id)?->checksum($bin), $bin));
    }

    /**
     * Estimated torque and power of the original file and, when the editor sends one, of a modified buffer.
     */
    public function dyno(DynoRequest $request, EcuFile $file): JsonResponse
    {
        $maps = array_map(fn (array $m): MapDef => MapDef::fromArray($m), $file->maps ?? []);

        return response()->json((new DynoEstimator)->estimate($maps, $request->engineSpec(), $file->bin(), $request->buffer(), $request->roleOverrides()));
    }

    /**
     * Store a modified version, optionally correcting checksums.
     */
    public function saveVersion(Request $request, EcuFile $file): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:8192'],
            'label' => ['nullable', 'string', 'max:200'],
            'fix' => ['nullable', 'boolean'],
            'changed_maps' => ['nullable', 'string'],
        ]);
        $bin = new Bin($request->file('file')->getContent());
        if ($bin->size !== $file->size) {
            return response()->json(['message' => 'Modified file size differs from the original.'], 422);
        }

        $dtc = new DtcService;
        $original = $file->bin();
        $protected = $dtc->protectedChanges($original, $bin, $dtc->table($original, (string) $file->family_id), (string) $file->family_id);
        if ($protected) {
            return response()->json([
                'message' => 'Emissions-related fault paths were changed ('.implode(', ', array_slice($protected, 0, 8)).'). BinBench does not save files with emissions monitoring switched off.',
            ], 422);
        }

        $analyzer = new Analyzer;
        $fixed = false;
        if ($request->boolean('fix')) {
            try {
                $bin = $analyzer->fixChecksums($bin, $file->family_id);
                $fixed = true;
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Checksum correction failed: '.$e->getMessage()], 422);
            }
        }

        $path = 'bins/'.Str::uuid().'.bin';
        Storage::disk('local')->put($path, $bin->data);
        $version = $file->versions()->create([
            'label' => $request->input('label') ?: 'Version '.($file->versions()->count() + 1),
            'path' => $path,
            'sha1' => sha1($bin->data),
            'checksum_fixed' => $fixed,
            'checksums' => $analyzer->checksumReport($analyzer->family($file->family_id)?->checksum($bin), $bin),
            'changed_maps' => json_decode($request->input('changed_maps', '[]'), true) ?: [],
        ]);

        return response()->json($version, 201);
    }

    public function downloadVersion(EcuFile $file, EcuFileVersion $version): Response
    {
        abort_unless($version->ecu_file_id === $file->id, 404);
        $name = Str::slug($file->name.' '.$version->label, '_').($version->checksum_fixed ? '_CHK' : '_NOCHK').'.bin';

        return response(Storage::disk('local')->get($version->path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function versionRaw(EcuFile $file, EcuFileVersion $version): Response
    {
        abort_unless($version->ecu_file_id === $file->id, 404);

        return response(Storage::disk('local')->get($version->path), 200, ['Content-Type' => 'application/octet-stream']);
    }

    public function destroyVersion(EcuFile $file, EcuFileVersion $version): Response
    {
        abort_unless($version->ecu_file_id === $file->id, 404);
        Storage::disk('local')->delete($version->path);
        $version->delete();

        return response()->noContent();
    }

    /**
     * Import an XDF as a definition for this file's software number and merge it into the map list.
     */
    public function importXdf(Request $request, EcuFile $file): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);
        $importer = new XdfImporter;
        try {
            $result = $importer->import($request->file('file')->getContent());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $maps = array_values(array_filter($result['maps'], fn (MapDef $m): bool => $m->address + $m->byteSize() <= $file->size));
        $file->user->mapDefinitions()->create([
            'name' => $result['title'] ?: $request->file('file')->getClientOriginalName(),
            'sw' => $file->sw,
            'family_id' => $file->family_id,
            'source_filename' => $request->file('file')->getClientOriginalName(),
            'map_count' => count($maps),
            'maps' => array_map(fn (MapDef $m): array => $m->toArray(), $maps),
        ]);

        return response()->json([
            ...$this->payload($file->analyze()),
            'importWarnings' => array_slice($importer->warnings, 0, 50),
            'imported' => count($maps),
        ]);
    }

    public function exportXdf(EcuFile $file): Response
    {
        $maps = array_map(fn (array $m): MapDef => MapDef::fromArray($m), $file->maps ?? []);
        $xml = (new XdfExporter)->export($maps, $file->name.' ('.$file->family.')', $file->size,
            'Exported from BinBench. SW '.($file->sw ?? '?'));

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($file->name, '_').'.xdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EcuFile $file): array
    {
        return [
            ...$file->only(['id', 'name', 'original_filename', 'size', 'sha1', 'family', 'family_id', 'sw', 'ident', 'maps', 'checksums', 'notes', 'created_at']),
            'versions' => $file->versions()->get(['id', 'label', 'sha1', 'checksum_fixed', 'checksums', 'changed_maps', 'created_at']),
            'definitions' => $file->sw ? MapDefinition::where('sw', $file->sw)->where('user_id', $file->user_id)->get(['id', 'name', 'source_filename', 'map_count', 'created_at']) : [],
        ];
    }
}
