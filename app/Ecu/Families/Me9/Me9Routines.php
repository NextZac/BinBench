<?php

namespace App\Ecu\Families\Me9;

/**
 * Fingerprints of the Bosch ME9 (MPC5xx) calibration lookup library.
 *
 * Key: first 16 hex chars of md5 over the routine body (entry up to the first blr, every "bl"
 * replaced by 0x48000001 so the called kernel's address does not matter). The routines are
 * found by hashing every "bl" target, so their addresses may differ between software versions.
 *
 * Kinds and argument registers:
 *  - KL  curve with header [n][x * n][z * n]:              r3 = header, r4 = input
 *  - KF  map with header [nx][ny][x * nx][y * ny][z]:      r3 = header, r4 = row input, r5 = column input
 *  - KFS map with separate pointers:                       r3 = nx, r4 = x, r5 = ny, r6 = y, r7 = z, r8/r9 = inputs
 *  - KLS curve with separate pointers:                     r3 = n, r4 = x, r5 = z, r6 = input
 *  - AX  shared-axis search [n][x * n] -> index/fraction:  r3 = axis, r4 = input; result stored to RAM
 *  - KER interpolation kernel for group maps:              r3 = z, r4 = column count, r5/r6 = row/column index vars
 * Z data is row-major: z[row * cols + col], the row axis belongs to the first input.
 * A null 'z' means the value type comes from the interpolation kernel the routine calls.
 */
class Me9Routines
{
    /** @var array<string, array{kind: string, count?: string, x?: string, y?: string, z?: ?string, step?: bool}> */
    public const TABLE = [
        // curves with header
        '67da69de3afce627' => ['kind' => 'KL', 'count' => 'u8', 'x' => 'u8', 'z' => 'u8'],
        '0ac2d5919a0e786a' => ['kind' => 'KL', 'count' => 'u8', 'x' => 's8', 'z' => 's8'],
        'c237d6461bf1d540' => ['kind' => 'KL', 'count' => 'u16', 'x' => 'u16', 'z' => 'u16'],
        '5cc342c5c94a2c69' => ['kind' => 'KL', 'count' => 'u16', 'x' => 's16', 'z' => 's16'],
        'fd61c50283808b9d' => ['kind' => 'KL', 'count' => 'u8', 'x' => 'u8', 'z' => 'u8', 'step' => true],
        'f0f5574f37ae2ea5' => ['kind' => 'KL', 'count' => 'u16', 'x' => 'u16', 'z' => 'u16', 'step' => true],
        // maps with header (value type from the kernel unless fixed)
        '9762bcdce0f2caf6' => ['kind' => 'KF', 'count' => 'u8', 'x' => 'u8', 'y' => 'u8', 'z' => null],
        '1495325cf011be62' => ['kind' => 'KF', 'count' => 'u16', 'x' => 'u16', 'y' => 'u16', 'z' => null],
        '853f7a0b9132976b' => ['kind' => 'KF', 'count' => 'u16', 'x' => 's16', 'y' => 's16', 'z' => null],
        'c7e8bd89dba19429' => ['kind' => 'KF', 'count' => 'u8', 'x' => 'u8', 'y' => 'u8', 'z' => 'u8', 'step' => true],
        // maps with separate pointers
        '0b6f896716f0ad7d' => ['kind' => 'KFS', 'x' => 'u8', 'y' => 's8', 'z' => null],
        'af697b57dd1519fc' => ['kind' => 'KFS', 'x' => 'u8', 'y' => 'u8', 'z' => null],
        'e0a808d5eb5dd279' => ['kind' => 'KFS', 'x' => 'u8', 'y' => 'u16', 'z' => null],
        'de2582b2d0c1b9a4' => ['kind' => 'KFS', 'x' => 'u16', 'y' => 'u8', 'z' => null],
        '6c87454277797b74' => ['kind' => 'KFS', 'x' => 's16', 'y' => 'u8', 'z' => null],
        '42e6bb69eefd8391' => ['kind' => 'KFS', 'x' => 's16', 'y' => 'u16', 'z' => null],
        'af223787ecebde9c' => ['kind' => 'KFS', 'x' => 's8', 'y' => 'u8', 'z' => null],
        // curves with separate pointers
        'b08daf769272cc3e' => ['kind' => 'KLS', 'x' => 'u8', 'z' => 's8'],
        '8c6bef33e2ccd0e7' => ['kind' => 'KLS', 'x' => 's8', 'z' => 'u8'],
        'f02cf935c1881a71' => ['kind' => 'KLS', 'x' => 'u8', 'z' => 'u16'],
        '577f0c43f9f08a36' => ['kind' => 'KLS', 'x' => 's16', 'z' => 'u16'],
        '15e32db4e8bc040a' => ['kind' => 'KLS', 'x' => 'u16', 'z' => 's16'],
        '6abaf5a4f259501a' => ['kind' => 'KLS', 'x' => 'u16', 'z' => 'u8'],
        // shared-axis search
        'fc9f70e409cfe137' => ['kind' => 'AX', 'x' => 'u8'],
        '530ca54e46403ec4' => ['kind' => 'AX', 'x' => 'u16'],
        '4c4ab1e29406c3a7' => ['kind' => 'AX', 'x' => 's16'],
        'ed3a903cab213b87' => ['kind' => 'AX', 'x' => 'u8'],
        '4a34e2f71ca9e3e2' => ['kind' => 'AX', 'x' => 's8'],
        '469ca168e785b349' => ['kind' => 'AX', 'x' => 'u16'],
        'e1997b0757619eab' => ['kind' => 'AX', 'x' => 's16'],
        // group-map interpolation kernels
        '93c3b386da775dc5' => ['kind' => 'KER', 'z' => 'u8'],
        '9bfcbda640e64790' => ['kind' => 'KER', 'z' => 's8'],
        '4c3037293dea7b9a' => ['kind' => 'KER', 'z' => 'u16'],
        '3dfbb56106181fa1' => ['kind' => 'KER', 'z' => 's16'],
        '1eeb0e5d415b58cc' => ['kind' => 'KER', 'z' => 'u8', 'step' => true],
    ];
}
