<?php

namespace Tests\Support;

/**
 * Builds small fake dumps containing inline-axis maps for scanner and API tests.
 */
class SyntheticBin
{
    private string $data;

    public function __construct(int $size = 0x80000, string $fill = "\xFF")
    {
        $this->data = str_repeat($fill, $size);
    }

    /**
     * @param  int[]  $words
     */
    public function words(int $at, array $words, bool $be = false): self
    {
        $bytes = pack($be ? 'n*' : 'v*', ...array_map(fn (int $w): int => $w & 0xFFFF, $words));
        $this->data = substr_replace($this->data, $bytes, $at, strlen($bytes));

        return $this;
    }

    public function ascii(int $at, string $text): self
    {
        $this->data = substr_replace($this->data, $text, $at, strlen($text));

        return $this;
    }

    /**
     * EDC15-style map: n, X[n], m, Y[m], Z[n*m]. Returns the data address.
     *
     * @param  int[]  $rowAxis
     * @param  int[]  $colAxis
     * @param  callable(int, int): int  $value
     */
    public function layoutA(int $at, array $rowAxis, array $colAxis, callable $value): int
    {
        $data = [];
        foreach (array_keys($rowAxis) as $r) {
            foreach (array_keys($colAxis) as $c) {
                $data[] = $value($r, $c);
            }
        }
        $this->words($at, [count($rowAxis), ...$rowAxis, count($colAxis), ...$colAxis, ...$data]);

        return $at + (2 + count($rowAxis) + count($colAxis)) * 2;
    }

    public function data(): string
    {
        return $this->data;
    }
}
