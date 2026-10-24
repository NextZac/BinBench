<?php

namespace App\Ecu\Families\Me7;

use App\Ecu\Bin;

/**
 * Reads the Bosch ME7 identification block(s):
 *  - ECUINFO-like string "42/1/ME7.5/5/4019.02//24C/Dst02o/220202//"
 *  - VAG ident "06A906032HN 1.8L R4/5VT         0002"
 *  - Bosch numbers "0261207440" + "1037363908" (usually adjacent)
 */
class Me7Ident
{
    /** @return array<string, mixed> */
    public static function read(Bin $bin): array
    {
        $out = ['notes' => []];
        // Only the first 256 KB carry ident data (boot + calibration).
        $head = substr($bin->data, 0, min($bin->size, 0x40000));

        if (preg_match('#\d{1,3}/\d/(ME7(?:\.\d){1,2})/(\d+)/([\w.]+)//(\w+)/(\w*)/(\d{6})/#', $head, $m)) {
            $out['variant'] = $m[1];
            $out['project'] = $m[3];
            $out['dataset'] = $m[4];
            $out['buildDate'] = $m[6];
        }
        // VAG part number + engine + software version.
        if (preg_match('/\b(\d[A-Z0-9]{2}9(?:06|07|09)\d{3}[A-Z]{0,3})\s+(\d\.\d\s?[lL][ A-Z0-9\/.]*?)\s{2,}([A-Z0-9 ]*?)\s*([0-9A-Z]{4})\b/', $head, $m)) {
            $out['partNumber'] = $m[1];
            $out['engine'] = trim(preg_replace('/\s+/', ' ', $m[2].' '.$m[3]));
            $out['swVersion'] = $m[4];
        }
        if (preg_match('/(0261\d{6})\s*(1037\d{6})/', $head, $m)) {
            $out['hw'] = $m[1];
            $out['sw'] = $m[2];
        } else {
            if (preg_match('/\b(0261\d{6})\b/', $head, $m)) {
                $out['hw'] = $m[1];
            }
            if (preg_match('/(1037\d{6})/', $head, $m)) {
                $out['sw'] = $m[1];
            }
        }
        if (empty($out['sw']) && preg_match('/\b(103[79]\d{6})\b/', $bin->data, $m)) {
            $out['sw'] = $m[1];
        }

        // Simple displacement-based vehicle hint is not reliable; leave vehicle unset.
        return $out;
    }

    /** Structural ME7 markers independent of the ident strings. */
    public static function structure(Bin $bin): array
    {
        $marks = [];
        // Boot block: C167 reset vector JMPS 0,xxxx and the next vectors into segment 0x82
        if ($bin->u8(0) === 0xFA && $bin->u8(4) === 0xFA) {
            $marks[] = 'C167 vector table';
        }
        // Bosch ME7 test-pattern string present in boot and calibration headers.
        if (strpos($bin->data, '12345678901234567890') !== false) {
            $marks[] = 'ME7 header signature';
        }
        // Start-up code: mov DPP3,#3 / DPP2 = RAM page 0xE0
        if ($bin->findPattern('E6 03 03 00', 0, 1) && $bin->findPattern('E6 02 E0 00', 0, 1)) {
            $marks[] = 'C167 DPP init';
        }

        return $marks;
    }
}
