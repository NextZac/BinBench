<?php

namespace App\Ecu;

/**
 * Extracts common Bosch / VAG identification strings from a dump.
 */
class IdStrings
{
    /**
     * @return array{hw: ?string, sw: ?string, partNumber: ?string}
     */
    public static function extract(Bin $bin): array
    {
        return [
            'hw' => self::first($bin, '/(02[68]1\d{6})/'),
            'sw' => self::first($bin, '/(1037\d{6})/') ?? self::first($bin, '/(1039S\d{5})/'),
            'partNumber' => self::first($bin, '/(?<![0-9A-Z])([0-9]{2}[0-9A-Z]9(?:06|07|09)[0-9]{3}[A-Z]{0,3})(?![0-9])/'),
        ];
    }

    public static function first(Bin $bin, string $regex): ?string
    {
        return preg_match($regex, $bin->data, $m) ? $m[1] : null;
    }

    public static function contains(Bin $bin, string $needle): bool
    {
        return str_contains($bin->data, $needle);
    }
}
