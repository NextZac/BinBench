<?php

namespace App\Ecu\Maps;

use App\Ecu\Bin;

/**
 * Checks a map definition that came from outside (the editor, an XDF) before it is stored:
 * known data types, sane sizes, finite scaling, and data and axes inside the file.
 */
class MapDefValidator
{
    public const DTYPES = ['u8', 's8', 'u16', 's16', 'u32', 's32', 'f32'];

    public const MAX_DIMENSION = 1024;

    public const MAX_CELLS = 65536;

    public const MAX_MAPS = 10000;

    public function __construct(private int $fileSize) {}

    /**
     * @param  mixed  $map  one map as sent by a client
     * @return list<string> problems; empty when the map can be stored
     */
    public function errors(mixed $map): array
    {
        if (! is_array($map)) {
            return ['is not an object'];
        }

        $errors = [];
        if (! is_string($map['name'] ?? null) || trim($map['name']) === '' || mb_strlen($map['name']) > 200) {
            $errors[] = 'needs a name of 1–200 characters';
        }
        foreach (['description' => 2000, 'category' => 100, 'unit' => 50, 'source' => 50, 'id' => 300] as $key => $max) {
            if (isset($map[$key]) && (! is_string($map[$key]) || mb_strlen($map[$key]) > $max)) {
                $errors[] = "{$key} must be text of at most {$max} characters";
            }
        }
        foreach (['factor', 'offset', 'confidence'] as $key) {
            if (isset($map[$key]) && ! $this->isFiniteNumber($map[$key])) {
                $errors[] = "{$key} must be a finite number";
            }
        }
        if (isset($map['confidence']) && $this->isFiniteNumber($map['confidence']) && ($map['confidence'] < 0 || $map['confidence'] > 1)) {
            $errors[] = 'confidence must be between 0 and 1';
        }
        if (isset($map['decimals']) && ! $this->isIntBetween($map['decimals'], 0, 10)) {
            $errors[] = 'decimals must be 0–10';
        }

        $dtype = $map['dtype'] ?? 'u16';
        if (! in_array($dtype, self::DTYPES, true)) {
            $errors[] = 'has an unknown data type';
        }
        $rows = $map['rows'] ?? 1;
        $cols = $map['cols'] ?? 1;
        if (! $this->isIntBetween($rows, 1, self::MAX_DIMENSION) || ! $this->isIntBetween($cols, 1, self::MAX_DIMENSION)) {
            $errors[] = 'rows and cols must be 1–'.self::MAX_DIMENSION;
        } elseif ($rows * $cols > self::MAX_CELLS) {
            $errors[] = 'has more than '.self::MAX_CELLS.' cells';
        }
        if (! $this->isIntBetween($map['address'] ?? null, 0, $this->fileSize - 1)) {
            $errors[] = 'address is outside the file';
        }

        if ($errors === [] && (int) $map['address'] + $rows * $cols * Bin::typeSize($dtype) > $this->fileSize) {
            $errors[] = 'data runs past the end of the file';
        }

        foreach (['x' => $cols, 'y' => $rows] as $name => $expected) {
            if (isset($map[$name])) {
                foreach ($this->axisErrors($map[$name], is_int($expected) ? $expected : null) as $error) {
                    $errors[] = "{$name} axis {$error}";
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function axisErrors(mixed $axis, ?int $expectedCount): array
    {
        if (! is_array($axis)) {
            return ['is not an object'];
        }
        $count = $axis['count'] ?? null;
        if (! $this->isIntBetween($count, 1, self::MAX_DIMENSION)) {
            return ['count must be 1–'.self::MAX_DIMENSION];
        }
        if ($expectedCount !== null && $count !== $expectedCount) {
            return ["has {$count} points but the map has {$expectedCount}"];
        }

        $errors = [];
        $dtype = $axis['dtype'] ?? 'u16';
        if (! in_array($dtype, self::DTYPES, true)) {
            $errors[] = 'has an unknown data type';
        }
        foreach (['factor', 'offset'] as $key) {
            if (isset($axis[$key]) && ! $this->isFiniteNumber($axis[$key])) {
                $errors[] = "{$key} must be a finite number";
            }
        }
        foreach (['unit' => 50, 'name' => 200] as $key => $max) {
            if (isset($axis[$key]) && (! is_string($axis[$key]) || mb_strlen($axis[$key]) > $max)) {
                $errors[] = "{$key} must be text of at most {$max} characters";
            }
        }
        if (isset($axis['decimals']) && ! $this->isIntBetween($axis['decimals'], 0, 10)) {
            $errors[] = 'decimals must be 0–10';
        }

        if (isset($axis['address'])) {
            if (! $this->isIntBetween($axis['address'], 0, $this->fileSize - 1)) {
                $errors[] = 'address is outside the file';
            } elseif (in_array($dtype, self::DTYPES, true) && $this->fileSize < $axis['address'] + $count * Bin::typeSize($dtype)) {
                $errors[] = 'runs past the end of the file';
            }
        } elseif (! is_array($axis['values'] ?? null)) {
            $errors[] = 'needs an address or fixed values';
        }
        if (isset($axis['values'])) {
            if (! is_array($axis['values']) || ! array_is_list($axis['values']) || count($axis['values']) !== $count) {
                $errors[] = "values must be a list of {$count} numbers";
            } elseif (array_filter($axis['values'], fn (mixed $v): bool => ! $this->isFiniteNumber($v)) !== []) {
                $errors[] = 'values must be finite numbers';
            }
        }

        return $errors;
    }

    private function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function isIntBetween(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }
}
