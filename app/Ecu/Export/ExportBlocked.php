<?php

namespace App\Ecu\Export;

use RuntimeException;

/**
 * Thrown by the ExportGate when a file's checksums cannot be verified and the user has not
 * acknowledged that. Carries what the user needs to decide.
 */
class ExportBlocked extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $checksums  checksum report of the file as it would be exported
     */
    public function __construct(public readonly string $reason, public readonly array $checksums)
    {
        parent::__construct($reason);
    }
}
