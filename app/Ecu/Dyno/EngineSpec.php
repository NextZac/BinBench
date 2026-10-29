<?php

namespace App\Ecu\Dyno;

/**
 * The few engine facts the dyno estimate needs. Taken from the ident string where possible.
 */
class EngineSpec
{
    public function __construct(
        public float $displacement = 2.0,
        public int $cylinders = 4,
        public bool $diesel = true,
        public ?float $stockPower = null,
        public bool $assumed = true,
    ) {}

    /**
     * @param  array<string, mixed>  $ident  analyzer ident of the file
     * @param  array{displacement?: ?float, cylinders?: ?int, stock_power?: ?float}  $overrides  user input wins
     */
    public static function fromIdent(array $ident, string $familyId, array $overrides = []): self
    {
        $engine = (string) ($ident['engine'] ?? '');
        $diesel = ($ident['fuel'] ?? null) === 'Diesel' || str_starts_with($familyId, 'edc');

        $displacement = preg_match('/(\d)[.,](\d)\s?l/i', $engine, $m) ? (float) "{$m[1]}.{$m[2]}" : null;
        $cylinders = preg_match('/\b[RVL](\d{1,2})\b/i', $engine, $m) ? (int) $m[1] : null;
        $assumed = $displacement === null || $cylinders === null;

        $displacement = (float) ($overrides['displacement'] ?? $displacement ?? 2.0);
        $cylinders = (int) ($overrides['cylinders'] ?? $cylinders ?? ($displacement >= 2.6 ? 6 : 4));
        if (isset($overrides['displacement'], $overrides['cylinders'])) {
            $assumed = false;
        }

        return new self($displacement, $cylinders, $diesel, $overrides['stock_power'] ?? null, $assumed);
    }

    /** Swept volume of one cylinder in m³. */
    public function cylinderVolume(): float
    {
        return $this->displacement / $this->cylinders / 1000;
    }

    /**
     * @return array{displacement: float, cylinders: int, fuel: string, stockPower: ?float, assumed: bool}
     */
    public function toArray(): array
    {
        return [
            'displacement' => $this->displacement,
            'cylinders' => $this->cylinders,
            'fuel' => $this->diesel ? 'diesel' : 'petrol',
            'stockPower' => $this->stockPower,
            'assumed' => $this->assumed,
        ];
    }
}
