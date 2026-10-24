<?php

namespace App\Ecu\Checksum;

use App\Ecu\Bin;

/**
 * A checksum algorithm for one ECU family.
 *
 * check() returns one entry per checksum block found:
 *   ['name' => string, 'start' => int, 'end' => int, 'stored' => string(hex), 'calculated' => string(hex), 'ok' => bool]
 */
interface ChecksumModule
{
    public function name(): string;

    /** True if the algorithm recognises the layout of this file. */
    public function supports(Bin $bin): bool;

    /** @return array<int, array{name:string,start:int,end:int,stored:string,calculated:string,ok:bool}> */
    public function check(Bin $bin): array;

    /** Whether fix() can actually correct (vs. detection only). */
    public function canFix(): bool;

    /** Return a corrected copy. Must throw \RuntimeException if it cannot fully correct. */
    public function fix(Bin $bin): Bin;
}
