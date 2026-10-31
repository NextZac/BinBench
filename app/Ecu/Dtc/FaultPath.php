<?php

namespace App\Ecu\Dtc;

/**
 * One fault path (Bosch "Fehlerpfad"): the P-codes it can report and the calibration fields that
 * decide whether and how it is reported.
 */
class FaultPath
{
    /**
     * @param  array<int, array{slot: string, address: int, code: int}>  $codes  P-code slots (code stored as hex digits, 0x0301 = P0301; 0 = unused)
     * @param  array<int, array{key: string, label: string, address: int, size: int, value: int, offValue: int}>  $fields  per-path settings
     *                                                                                                                     (error class, enable/OBD masks...) and the value that switches the path off
     */
    public function __construct(
        public int $index,
        public array $codes = [],
        public array $fields = [],
        public ?string $name = null,
    ) {}

    /**
     * Distinct P-codes this path reports (non-zero).
     *
     * @return int[]
     */
    public function pcodes(): array
    {
        return array_values(array_unique(array_filter(array_column($this->codes, 'code'), fn (int $code): bool => ! DtcCatalog::isUnused($code))));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'codes' => $this->codes,
            'fields' => $this->fields,
        ];
    }
}
