<?php

namespace App\Http\Controllers\Api;

use App\Ecu\Maps\MapDef;
use App\Ecu\Tuning\Stage1Options;
use App\Ecu\Tuning\Stage1Planner;
use App\Ecu\Tuning\TuningAdvisor;
use App\Http\Controllers\Controller;
use App\Http\Requests\DynoRequest;
use App\Models\EcuFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

class TuningController extends Controller
{
    /**
     * Rule-based Stage 1 proposal for the editor's current buffer, with an estimated dyno preview.
     */
    public function stage1(DynoRequest $request, EcuFile $file, TuningAdvisor $advisor): JsonResponse
    {
        $plan = $this->plan($request, $file);

        return response()->json([
            ...$plan,
            'aiConfigured' => $advisor->isConfigured(),
            'prompt' => $plan['available'] ? TuningAdvisor::copyablePrompt(TuningAdvisor::summarize($plan, $this->ecuSummary($file))) : null,
        ]);
    }

    /**
     * The same proposal, explained and reviewed by the configured AI provider.
     */
    public function explain(DynoRequest $request, EcuFile $file, TuningAdvisor $advisor): JsonResponse
    {
        if (! $advisor->isConfigured()) {
            return response()->json(['message' => 'No AI provider is configured. Set TUNING_AI_BASE_URL and TUNING_AI_MODEL in .env.'], 503);
        }
        $plan = $this->plan($request, $file);
        if (! $plan['available']) {
            return response()->json(['message' => $plan['reason']], 422);
        }

        try {
            $text = $advisor->explain(TuningAdvisor::summarize($plan, $this->ecuSummary($file)));
        } catch (ConnectionException|RequestException|\RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'The AI provider did not answer: '.$e->getMessage()], 502);
        }

        return response()->json(['explanation' => $text, 'model' => config('services.tuning_ai.model')]);
    }

    /**
     * @return array{family: ?string, software: ?string, engine: ?string}
     */
    private function ecuSummary(EcuFile $file): array
    {
        return [
            'family' => $file->family,
            'software' => $file->sw,
            'engine' => $file->ident['engine'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(DynoRequest $request, EcuFile $file): array
    {
        $options = $request->validate([
            'fuel_increase' => ['nullable', 'numeric', 'between:0,40'],
            'boost_increase' => ['nullable', 'numeric', 'between:0,25'],
            'max_boost' => ['nullable', 'numeric', 'between:1000,4000'],
            'max_fuel' => ['nullable', 'numeric', 'between:5,200'],
            'load_increase' => ['nullable', 'numeric', 'between:0,40'],
            'max_load' => ['nullable', 'numeric', 'between:50,250'],
            'max_torque' => ['nullable', 'numeric', 'between:50,2000'],
            'response_increase' => ['nullable', 'numeric', 'between:0,40'],
            'aspiration' => ['nullable', 'in:auto,turbo,na'],
        ]);
        $maps = array_map(fn (array $m): MapDef => MapDef::fromArray($m), $file->maps ?? []);
        $original = $file->bin();

        return (new Stage1Planner)->plan($maps, $request->engineSpec(), $original, $request->buffer() ?? $original, Stage1Options::fromArray($options), $request->roleOverrides());
    }
}
