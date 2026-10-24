<?php

namespace App\Ecu\Maps;

/**
 * Map axis. Either stored in the bin ($address set) or given as fixed $values.
 */
class Axis
{
    public function __construct(
        public int $count,
        public ?int $address = null,
        public string $dtype = 'u16',
        public bool $be = false,
        public float $factor = 1.0,
        public float $offset = 0.0,
        public string $unit = '',
        public string $name = '',
        public ?array $values = null,
        public int $decimals = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'address' => $this->address,
            'dtype' => $this->dtype,
            'be' => $this->be,
            'factor' => $this->factor,
            'offset' => $this->offset,
            'unit' => $this->unit,
            'name' => $this->name,
            'values' => $this->values,
            'decimals' => $this->decimals,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['count'], isset($a['address']) ? (int) $a['address'] : null, $a['dtype'] ?? 'u16',
            (bool) ($a['be'] ?? false), (float) ($a['factor'] ?? 1), (float) ($a['offset'] ?? 0),
            $a['unit'] ?? '', $a['name'] ?? '', $a['values'] ?? null, (int) ($a['decimals'] ?? 0),
        );
    }
}
