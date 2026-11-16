<?php

namespace App\Http\Requests;

use App\Ecu\Maps\MapDef;
use App\Ecu\Maps\MapDefValidator;
use App\Models\EcuFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Name, notes and the map list of a file. Maps are checked against the file before they are stored,
 * because Stage 1, the dyno and the editor read the bin through them.
 */
class UpdateEcuFileRequest extends FormRequest
{
    private const MAX_REPORTED_ERRORS = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'maps' => ['sometimes', 'array', 'list', 'max:'.MapDefValidator::MAX_MAPS],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! is_array($this->input('maps')) || $validator->errors()->has('maps')) {
                    return;
                }
                $checker = new MapDefValidator($this->ecuFile()->size);
                $reported = 0;
                foreach ($this->input('maps') as $index => $map) {
                    foreach ($checker->errors($map) as $error) {
                        if ($reported++ >= self::MAX_REPORTED_ERRORS) {
                            return;
                        }
                        $label = is_array($map) && is_string($map['name'] ?? null) ? "\"{$map['name']}\"" : "#{$index}";
                        $validator->errors()->add("maps.{$index}", "Map {$label} {$error}.");
                    }
                }
            },
        ];
    }

    public function ecuFile(): EcuFile
    {
        return $this->route('file');
    }

    /**
     * The maps as BinBench stores them: rebuilt through MapDef, so unknown keys are dropped.
     *
     * @return list<array<string, mixed>>|null
     */
    public function normalizedMaps(): ?array
    {
        if (! $this->has('maps')) {
            return null;
        }

        return array_map(fn (array $m): array => MapDef::fromArray($m)->toArray(), $this->input('maps'));
    }
}
