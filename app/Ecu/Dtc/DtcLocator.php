<?php

namespace App\Ecu\Dtc;

use App\Ecu\Bin;

/**
 * Finds the fault-code tables of one ECU family. Returns null when the layout is not understood;
 * never guesses addresses it can't back up.
 */
interface DtcLocator
{
    public function locate(Bin $bin): ?DtcTable;
}
