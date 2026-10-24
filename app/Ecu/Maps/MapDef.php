<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Definition of a calibration table inside a binary.
 * Physical value = raw * factor + offset. Data is stored row-major unless $colMajor.
 * X axis runs along columns, Y axis along rows.
 */
class MapDef
{
    public ?Axis $x = null;

    public ?Axis $y = null;

    public function __construct(
        public string $name,
        public int $address,
        public int $rows = 1,
        public int $cols = 1,
        public string $dtype = 'u16',
        public bool $be = false,
        public float $factor = 1.0,
        public float $offset = 0.0,
        public string $unit = '',
        public string $category = 'Other',
        public string $description = '',
        public string $source = 'scan',
        public float $confidence = 0.5,
        public bool $colMajor = false,
        public int $decimals = 2,
        public ?string $id = null,
    ) {
        $this->id ??= sprintf('%s@%X', preg_replace('/\W+/', '_', $name), $address);
    }

    public function kind(): string
    {
        return $this->rows > 1 && $this->cols > 1 ? 'map' : (($this->rows * $this->cols) > 1 ? 'curve' : 'value');
    }

    public function byteSize(): int
    {
        return $this->rows * $this->cols * Bin::typeSize($this->dtype);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'source' => $this->source,
            'confidence' => round($this->confidence, 2),
            'kind' => $this->kind(),
            'address' => $this->address,
            'rows' => $this->rows,
            'cols' => $this->cols,
            'dtype' => $this->dtype,
            'be' => $this->be,
            'factor' => $this->factor,
            'offset' => $this->offset,
            'unit' => $this->unit,
            'decimals' => $this->decimals,
            'colMajor' => $this->colMajor,
            'x' => $this->x?->toArray(),
            'y' => $this->y?->toArray(),
        ];
    }

    public static function fromArray(array $a): self
    {
        $m = new self(
            $a['name'], (int) $a['address'], (int) ($a['rows'] ?? 1), (int) ($a['cols'] ?? 1),
            $a['dtype'] ?? 'u16', (bool) ($a['be'] ?? false), (float) ($a['factor'] ?? 1), (float) ($a['offset'] ?? 0),
            $a['unit'] ?? '', $a['category'] ?? 'Other', $a['description'] ?? '', $a['source'] ?? 'user',
            (float) ($a['confidence'] ?? 1), (bool) ($a['colMajor'] ?? false), (int) ($a['decimals'] ?? 2), $a['id'] ?? null,
        );
        $m->x = isset($a['x']) ? Axis::fromArray($a['x']) : null;
        $m->y = isset($a['y']) ? Axis::fromArray($a['y']) : null;

        return $m;
    }
}
