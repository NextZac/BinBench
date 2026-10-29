<?php

namespace App\Http\Requests;

use App\Ecu\Bin;
use App\Models\EcuFile;
use Illuminate\Validation\Validator;

/**
 * Diff between two dumps of the same size. Each side is "original", "version:{id}" (a saved
 * version of this file) or "file:{id}" (another file of the same user). The editor's working
 * buffer can be sent as the raw body; it replaces the side named by "buffer_side" (default target).
 */
class DiffRequest extends DynoRequest
{
    private const SOURCE_PATTERN = '/^(original|version:\d+|file:\d+)$/';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'base' => ['nullable', 'string', 'regex:'.self::SOURCE_PATTERN],
            'target' => ['nullable', 'string', 'regex:'.self::SOURCE_PATTERN],
            'buffer_side' => ['nullable', 'in:base,target'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                foreach (['base', 'target'] as $side) {
                    $error = $this->sourceError($this->input($side));
                    if ($error !== null) {
                        $validator->errors()->add($side, $error);
                    }
                }
            },
        ];
    }

    /**
     * @return array{bin: Bin, label: string, sw: ?string}
     */
    public function base(): array
    {
        return $this->side('base');
    }

    /**
     * @return array{bin: Bin, label: string, sw: ?string}
     */
    public function target(): array
    {
        return $this->side('target');
    }

    /**
     * @return array{bin: Bin, label: string, sw: ?string}
     */
    private function side(string $side): array
    {
        $buffer = $this->buffer();
        if ($buffer !== null && $this->input('buffer_side', 'target') === $side) {
            return ['bin' => $buffer, 'label' => 'Current edits', 'sw' => $this->ecuFile()->sw];
        }

        return $this->resolve($this->input($side) ?? 'original');
    }

    /**
     * @return array{bin: Bin, label: string, sw: ?string}
     */
    private function resolve(string $source): array
    {
        $file = $this->ecuFile();
        [$kind, $id] = array_pad(explode(':', $source, 2), 2, null);

        return match ($kind) {
            'version' => (function () use ($file, $id): array {
                $version = $file->versions()->findOrFail($id);

                return ['bin' => $version->bin(), 'label' => $version->label, 'sw' => $file->sw];
            })(),
            'file' => (function () use ($id): array {
                $other = EcuFile::findOrFail($id);

                return ['bin' => $other->bin(), 'label' => $other->name, 'sw' => $other->sw];
            })(),
            default => ['bin' => $file->bin(), 'label' => 'Original', 'sw' => $file->sw],
        };
    }

    private function sourceError(?string $source): ?string
    {
        if ($source === null || $source === 'original') {
            return null;
        }
        [$kind, $id] = explode(':', $source, 2);
        $file = $this->ecuFile();

        if ($kind === 'version') {
            return $file->versions()->whereKey($id)->exists() ? null : 'Unknown version.';
        }

        $other = EcuFile::find($id);
        if ($other === null || $this->user()?->cannot('view', $other)) {
            return 'Unknown file.';
        }

        return $other->size === $file->size ? null : 'Files of different size cannot be compared.';
    }
}
