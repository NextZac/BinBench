<?php

namespace App\Ecu\Patches;

use App\Ecu\Bin;
use App\Ecu\Maps\MapDef;

/**
 * A firmware code change for specific software numbers (multimap, switches, ...), as opposed to a map edit.
 *
 * Every patch states exactly which bytes it expects before it writes, so it is only offered on a buffer
 * where all of them match, and it can be removed by writing those bytes back.
 */
interface CodePatch
{
    /** Short id used in URLs, e.g. "dde4-multimap-2". */
    public function id(): string;

    /** Family id (see EcuFamily::id()) this patch belongs to. */
    public function familyId(): string;

    /**
     * Static description for the editor.
     *
     * @return array{name: string, summary: string, details: string[], software: string[], tested: string, flash: string}
     */
    public function describe(): array;

    /**
     * Whether the patch can be applied to, or is already in, this buffer.
     *
     * @return array{status: 'available'|'installed'|'unavailable', reason: ?string}
     */
    public function status(Bin $bin): array;

    /**
     * Byte writes that install the patch. Only valid when status() is 'available'.
     *
     * @return array<int, array{address: int, bytes: string}>
     */
    public function install(Bin $bin): array;

    /**
     * Byte writes that take the patch out again. Only valid when status() is 'installed'.
     *
     * @return array<int, array{address: int, bytes: string}>
     */
    public function uninstall(Bin $bin): array;

    /**
     * Map definitions for the tables the patch switches between (the original tables and their copies).
     *
     * @return MapDef[]
     */
    public function maps(Bin $bin): array;
}
