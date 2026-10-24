<?php

namespace App\Ecu\Xdf;

/**
 * Reduces a TunerPro equation in X (e.g. "0.1 * X + -273.14", "(X-64)*0.75") to factor/offset.
 * Non-linear equations return null.
 */
class LinearEquation
{
    /**
     * @return array{factor: float, offset: float}|null
     */
    public static function parse(string $equation): ?array
    {
        $equation = trim($equation);
        if ($equation === '' || $equation === 'X') {
            return ['factor' => 1.0, 'offset' => 0.0];
        }
        try {
            $f0 = self::evaluate($equation, 0.0);
            $f1 = self::evaluate($equation, 1.0);
            $f7 = self::evaluate($equation, 7.0);
        } catch (\Throwable) {
            return null;
        }
        $factor = $f1 - $f0;
        if (abs(($f0 + 7 * $factor) - $f7) > 1e-6 * max(1.0, abs($f7))) {
            return null;
        }

        return ['factor' => $factor, 'offset' => $f0];
    }

    public static function format(float $factor, float $offset): string
    {
        $eq = rtrim(rtrim(sprintf('%.8F', $factor), '0'), '.').' * X';
        if ($offset != 0.0) {
            $eq .= ' + '.rtrim(rtrim(sprintf('%.8F', $offset), '0'), '.');
        }

        return $eq;
    }

    /**
     * Tiny recursive-descent evaluator: numbers, X, + - * / and parentheses.
     */
    public static function evaluate(string $expr, float $x): float
    {
        if (preg_replace('/(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?|[Xx()+\-*\/\s]/', '', $expr) !== '') {
            throw new \InvalidArgumentException("Unsupported equation: {$expr}");
        }
        preg_match_all('/(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|[Xx()+\-*\/]/', $expr, $m);
        $tokens = $m[0];
        $pos = 0;
        $value = self::expression($tokens, $pos, $x);
        if ($pos !== count($tokens)) {
            throw new \InvalidArgumentException("Unexpected token in equation: {$expr}");
        }

        return $value;
    }

    /**
     * @param  string[]  $t
     */
    private static function expression(array $t, int &$pos, float $x): float
    {
        $value = self::term($t, $pos, $x);
        while (isset($t[$pos]) && ($t[$pos] === '+' || $t[$pos] === '-')) {
            $op = $t[$pos++];
            $rhs = self::term($t, $pos, $x);
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    /**
     * @param  string[]  $t
     */
    private static function term(array $t, int &$pos, float $x): float
    {
        $value = self::factor($t, $pos, $x);
        while (isset($t[$pos]) && ($t[$pos] === '*' || $t[$pos] === '/')) {
            $op = $t[$pos++];
            $rhs = self::factor($t, $pos, $x);
            $value = $op === '*' ? $value * $rhs : $value / $rhs;
        }

        return $value;
    }

    /**
     * @param  string[]  $t
     */
    private static function factor(array $t, int &$pos, float $x): float
    {
        $tok = $t[$pos++] ?? throw new \InvalidArgumentException('Unexpected end of equation');
        if ($tok === '-') {
            return -self::factor($t, $pos, $x);
        }
        if ($tok === '+') {
            return self::factor($t, $pos, $x);
        }
        if ($tok === '(') {
            $value = self::expression($t, $pos, $x);
            if (($t[$pos++] ?? null) !== ')') {
                throw new \InvalidArgumentException('Missing )');
            }

            return $value;
        }
        if (strtoupper($tok) === 'X') {
            return $x;
        }
        if (is_numeric($tok)) {
            return (float) $tok;
        }
        throw new \InvalidArgumentException("Unsupported token {$tok}");
    }
}
