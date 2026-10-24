<?php

namespace App\Ecu;

/**
 * Immutable-ish wrapper around a raw ECU dump with typed little/big-endian readers.
 */
class Bin
{
    public readonly int $size;

    public function __construct(public string $data)
    {
        $this->size = strlen($data);
    }

    public static function fromFile(string $path): self
    {
        return new self(file_get_contents($path));
    }

    public function u8(int $o): int
    {
        return ord($this->data[$o]);
    }

    public function s8(int $o): int
    {
        $v = ord($this->data[$o]);

        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    public function u16(int $o, bool $be = false): int
    {
        $a = ord($this->data[$o]);
        $b = ord($this->data[$o + 1]);

        return $be ? ($a << 8) | $b : ($b << 8) | $a;
    }

    public function s16(int $o, bool $be = false): int
    {
        $v = $this->u16($o, $be);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    public function u32(int $o, bool $be = false): int
    {
        return $be
            ? ($this->u16($o, true) << 16) | $this->u16($o + 2, true)
            : ($this->u16($o + 2) << 16) | $this->u16($o);
    }

    /** Read a value of the given storage type: u8,s8,u16,s16,u32,s32,f32. */
    public function read(int $o, string $type, bool $be = false): int|float
    {
        return match ($type) {
            'u8' => $this->u8($o),
            's8' => $this->s8($o),
            'u16' => $this->u16($o, $be),
            's16' => $this->s16($o, $be),
            'u32' => $this->u32($o, $be),
            's32' => ($v = $this->u32($o, $be)) >= 0x80000000 ? $v - 0x100000000 : $v,
            'f32' => unpack($be ? 'G' : 'g', substr($this->data, $o, 4))[1],
        };
    }

    public static function typeSize(string $type): int
    {
        return match ($type) {
            'u8', 's8' => 1,
            'u16', 's16' => 2,
            default => 4,
        };
    }

    public function slice(int $o, int $len): string
    {
        return substr($this->data, $o, $len);
    }

    /**
     * Find a hex pattern with "XX"/"??" wildcards. Returns offsets of all matches.
     *
     * @return int[]
     */
    public function findPattern(string $hex, int $start = 0, int $limit = PHP_INT_MAX): array
    {
        $tokens = preg_split('/\s+/', trim($hex));
        $regex = '';
        foreach ($tokens as $t) {
            $regex .= (strtoupper($t) === 'XX' || $t === '??') ? '.' : preg_quote(chr(hexdec($t)), '/');
        }
        $out = [];
        if (preg_match_all('/(?='.$regex.')/s', substr($this->data, $start), $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $off]) {
                $out[] = $off + $start;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Printable ASCII runs of at least $min chars.
     *
     * @return array<int,string> offset => string
     */
    public function strings(int $min = 6, int $start = 0, ?int $end = null): array
    {
        $end ??= $this->size;
        preg_match_all('/[\x20-\x7E]{'.$min.',}/', substr($this->data, $start, $end - $start), $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$s, $off]) {
            $out[$off + $start] = $s;
        }

        return $out;
    }

    public function withPatch(int $o, string $bytes): self
    {
        return new self(substr_replace($this->data, $bytes, $o, strlen($bytes)));
    }
}
