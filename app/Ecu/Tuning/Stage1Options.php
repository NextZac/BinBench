<?php

namespace App\Ecu\Tuning;

/**
 * Limits for a Stage 1 proposal. Percentages are the increase at full load.
 */
class Stage1Options
{
    public function __construct(
        public float $fuelIncrease = 15.0,
        public float $boostIncrease = 8.0,
        public float $maxBoost = 2600.0,
        public ?float $maxFuel = null,
        public float $loadIncrease = 10.0,
        public float $maxLoad = 180.0,
        public ?float $maxTorque = null,
    ) {}

    /**
     * @param  array{fuel_increase?: ?float, boost_increase?: ?float, max_boost?: ?float, max_fuel?: ?float, load_increase?: ?float, max_load?: ?float, max_torque?: ?float}  $input
     */
    public static function fromArray(array $input): self
    {
        $defaults = new self;
        $value = fn (string $key, ?float $default): ?float => isset($input[$key]) ? (float) $input[$key] : $default;

        return new self(
            $value('fuel_increase', $defaults->fuelIncrease),
            $value('boost_increase', $defaults->boostIncrease),
            $value('max_boost', $defaults->maxBoost),
            $value('max_fuel', null),
            $value('load_increase', $defaults->loadIncrease),
            $value('max_load', $defaults->maxLoad),
            $value('max_torque', null),
        );
    }

    /**
     * @return array<string, ?float>
     */
    public function toArray(): array
    {
        return [
            'fuelIncrease' => $this->fuelIncrease,
            'boostIncrease' => $this->boostIncrease,
            'maxBoost' => $this->maxBoost,
            'maxFuel' => $this->maxFuel,
            'loadIncrease' => $this->loadIncrease,
            'maxLoad' => $this->maxLoad,
            'maxTorque' => $this->maxTorque,
        ];
    }
}
