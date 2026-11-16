<?php

namespace App\Ecu\Dtc;

/**
 * All fault paths found in a dump, as located by a family's DtcLocator.
 */
class DtcTable
{
    /**
     * @param  FaultPath[]  $paths
     * @param  string[]  $notes  what was found and how sure the locator is
     * @param  array<string, string>  $fieldHelp  field key => what the field does, for the UI
     * @param  int  $codeSize  bytes per stored code
     * @param  bool  $bigEndian  byte order of codes and multi-byte fields
     */
    public function __construct(
        public array $paths,
        public string $codeFormat = 'pcode-hex',
        public array $notes = [],
        public array $fieldHelp = [],
        public int $codeSize = 2,
        public bool $bigEndian = false,
    ) {}
}
