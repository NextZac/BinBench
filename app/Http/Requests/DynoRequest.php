<?php

namespace App\Http\Requests;

use App\Ecu\Bin;
use App\Ecu\Dyno\EngineSpec;
use App\Models\EcuFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Engine data in the query string plus, optionally, the editor's working buffer as the raw body.
 */
class DynoRequest extends FormRequest
{
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
            'displacement' => ['nullable', 'numeric', 'between:0.5,10'],
            'cylinders' => ['nullable', 'integer', 'between:1,16'],
            'stock_power' => ['nullable', 'numeric', 'between:20,2000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->sentBuffer() && strlen($this->getContent()) !== $this->ecuFile()->size) {
                    $validator->errors()->add('file', 'Modified file size differs from the original.');
                }
            },
        ];
    }

    public function ecuFile(): EcuFile
    {
        return $this->route('file');
    }

    /** The editor's working buffer, or null when only the original is wanted. */
    public function buffer(): ?Bin
    {
        return $this->sentBuffer() ? new Bin($this->getContent()) : null;
    }

    public function engineSpec(): EngineSpec
    {
        $file = $this->ecuFile();

        return EngineSpec::fromIdent($file->ident ?? [], (string) $file->family_id, [
            'displacement' => $this->filled('displacement') ? (float) $this->input('displacement') : null,
            'cylinders' => $this->filled('cylinders') ? (int) $this->input('cylinders') : null,
            'stock_power' => $this->filled('stock_power') ? (float) $this->input('stock_power') : null,
        ]);
    }

    private function sentBuffer(): bool
    {
        return str_contains((string) $this->header('Content-Type'), 'octet-stream') && $this->getContent() !== '';
    }
}
