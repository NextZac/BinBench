<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MapDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DefinitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->mapDefinitions()->latest()->get(['id', 'name', 'sw', 'family_id', 'source_filename', 'map_count', 'created_at']));
    }

    public function destroy(MapDefinition $definition): Response
    {
        $definition->delete();

        return response()->noContent();
    }
}
