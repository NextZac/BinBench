<?php

namespace App\Ecu\Tuning;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Asks a language model to explain and review a Stage 1 proposal. The model only sees a summary
 * (map names, value ranges, warnings, estimated power), never the file, and never chooses values.
 *
 * Works with any OpenAI-compatible chat completions API: DeepSeek, GitHub Models, OpenRouter,
 * a local Ollama, and so on (see services.tuning_ai).
 */
class TuningAdvisor
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You review ECU calibration changes for a tuning editor. You get a summary of a rule-based
        Stage 1 proposal: the ECU, the engine, each map that changes with its value range before and
        after, the tool's warnings and an estimated dyno result.

        Explain in plain language what each change does and why, then list the risks and what to
        check on a datalog before and after flashing. Point out anything in the numbers that looks
        inconsistent or aggressive. Only refer to values in the summary; do not invent map names,
        addresses or numbers. Keep it under 350 words, using short headings and bullet points.

        Never suggest removing, disabling or bypassing emission equipment or its monitoring
        (EGR, DPF/FAP, SCR/AdBlue, catalytic converter, lambda/O2 sensors, emission related DTCs).
        If asked, say that it is not supported.
        PROMPT;

    public function isConfigured(): bool
    {
        return filled(config('services.tuning_ai.base_url')) && filled(config('services.tuning_ai.model'));
    }

    /**
     * @param  array<string, mixed>  $summary
     *
     * @throws RequestException
     * @throws \RuntimeException when the model returns no text
     */
    public function explain(array $summary): string
    {
        $response = Http::baseUrl(rtrim((string) config('services.tuning_ai.base_url'), '/'))
            ->when(filled(config('services.tuning_ai.api_key')), fn ($http) => $http->withToken(config('services.tuning_ai.api_key')))
            ->acceptJson()
            ->timeout((int) config('services.tuning_ai.timeout', 60))
            ->post('chat/completions', [
                'model' => config('services.tuning_ai.model'),
                'temperature' => 0.3,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => self::summaryJson($summary)],
                ],
            ])
            ->throw();

        $text = trim((string) $response->json('choices.0.message.content'));
        if ($text === '') {
            throw new \RuntimeException('The AI provider returned an empty answer.');
        }

        return $text;
    }

    /**
     * The same instructions and summary as one block of text, to paste into any chat assistant
     * (Copilot Chat, ChatGPT, DeepSeek, ...) when no provider is configured.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function copyablePrompt(array $summary): string
    {
        return self::SYSTEM_PROMPT."\n\nHere is the proposal summary as JSON:\n\n```json\n".self::summaryJson($summary)."\n```\n";
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryJson(array $summary): string
    {
        return json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Reduces a plan to what the model needs: no cell grids, no file contents.
     *
     * @param  array<string, mixed>  $plan  Stage1Planner::plan() result
     * @param  array<string, mixed>  $ecu
     * @return array<string, mixed>
     */
    public static function summarize(array $plan, array $ecu): array
    {
        $dyno = $plan['dyno'] ?? null;
        $peaks = fn (?array $curve): ?array => $curve ? [
            'peakPowerPs' => $curve['peakPower']['value'], 'atRpm' => $curve['peakPower']['rpm'],
            'peakTorqueNm' => $curve['peakTorque']['value'], 'torqueAtRpm' => $curve['peakTorque']['rpm'],
        ] : null;

        return [
            'ecu' => $ecu,
            'engine' => $dyno['engine'] ?? null,
            'options' => $plan['options'],
            'changes' => array_map(fn (array $p): array => [
                'map' => $p['name'],
                'unit' => $p['unit'],
                'cellsChanged' => $p['changedCells'],
                'before' => $p['before'],
                'after' => $p['after'],
                'rule' => $p['summary'],
            ], $plan['proposals']),
            'warnings' => $plan['warnings'],
            'estimatedDyno' => $dyno ? [
                'calibratedToStockPower' => $dyno['calibrated'],
                'original' => $peaks($dyno['stock']),
                'proposed' => $peaks($dyno['modified']),
            ] : null,
        ];
    }
}
