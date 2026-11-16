<?php

namespace Tests\Unit;

use App\Ecu\Analyzer;
use App\Ecu\Bin;
use App\Ecu\Checksum\ChecksumModule;
use App\Ecu\Export\ExportBlocked;
use App\Ecu\Export\ExportGate;
use App\Ecu\Families\EcuFamily;
use App\Ecu\Maps\MapDef;
use PHPUnit\Framework\TestCase;

/**
 * The gate with a toy ECU: one checksum byte at the end holding the sum of all bytes before it.
 */
class ExportGateTest extends TestCase
{
    private Bin $original;

    /** @var MapDef[] */
    private array $maps;

    protected function setUp(): void
    {
        parent::setUp();
        $body = str_repeat("\x01", 255);
        $this->original = new Bin($body.chr(array_sum(unpack('C*', $body)) & 0xFF));
        $this->maps = [new MapDef('Fuel', 0x10, 1, 8, 'u8', id: 'fuel')];
    }

    public function test_untouched_valid_file_is_verified_and_byte_identical(): void
    {
        $export = $this->gate()->prepare($this->original, $this->original, 'toy', $this->maps);

        $this->assertSame(ExportGate::VERIFIED, $export['state']);
        $this->assertFalse($export['corrected']);
        $this->assertSame($this->original->data, $export['bin']->data);
        $this->assertSame(0, $export['changes']['changedBytes']);
        $this->assertSame(0, $export['changes']['correction']['bytes']);
    }

    public function test_correction_only_writes_the_checksum_and_lists_it(): void
    {
        $modified = $this->original->withPatch(0x12, "\x09");

        $export = $this->gate()->prepare($this->original, $modified, 'toy', $this->maps);

        $this->assertSame(ExportGate::VERIFIED, $export['state']);
        $this->assertTrue($export['corrected']);
        $this->assertTrue($export['checksums']['ok']);
        $this->assertSame([['start' => 255, 'end' => 256]], $export['changes']['correction']['ranges']);
        $this->assertSame(substr($modified->data, 0, 255), substr($export['bin']->data, 0, 255));
        $this->assertSame('Fuel', $export['changes']['maps'][0]['name']);
        $this->assertSame(1, $export['changes']['maps'][0]['changedCells']);
    }

    public function test_blocks_when_correction_is_switched_off(): void
    {
        $modified = $this->original->withPatch(0x12, "\x09");

        try {
            $this->gate()->prepare($this->original, $modified, 'toy', $this->maps, correct: false);
            $this->fail('Expected the export to be blocked.');
        } catch (ExportBlocked $e) {
            $this->assertStringContainsString('switched off', $e->reason);
            $this->assertFalse($e->checksums['ok']);
        }

        $export = $this->gate()->prepare($this->original, $modified, 'toy', $this->maps, correct: false, acknowledgeUnverified: true);
        $this->assertSame(ExportGate::UNVERIFIED, $export['state']);
        $this->assertSame($modified->data, $export['bin']->data);
    }

    public function test_blocks_modules_that_can_only_check(): void
    {
        $this->expectException(ExportBlocked::class);
        $this->expectExceptionMessage('can only check');

        $this->gate(canFix: false)->prepare($this->original, $this->original->withPatch(0x12, "\x09"), 'toy', $this->maps);
    }

    public function test_verify_only_module_passes_a_file_that_is_still_valid(): void
    {
        // swap two bytes: the sum, and so the checksum, stays valid
        $modified = $this->original->withPatch(0x12, "\x02\x00");

        $export = $this->gate(canFix: false)->prepare($this->original, $modified, 'toy', $this->maps);

        $this->assertSame(ExportGate::VERIFIED, $export['state']);
    }

    public function test_blocks_when_no_checksum_module_exists(): void
    {
        $this->expectException(ExportBlocked::class);
        $this->expectExceptionMessage('no checksum module');

        $this->gate(module: false)->prepare($this->original, $this->original, 'toy', $this->maps);
    }

    public function test_blocks_when_correction_does_not_validate(): void
    {
        $this->expectException(ExportBlocked::class);
        $this->expectExceptionMessage('does not validate');

        $this->gate(brokenFix: true)->prepare($this->original, $this->original->withPatch(0x12, "\x09"), 'toy', $this->maps);
    }

    public function test_rejects_a_buffer_of_another_size(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gate()->prepare($this->original, new Bin('short'), 'toy', $this->maps, acknowledgeUnverified: true);
    }

    private function gate(bool $canFix = true, bool $module = true, bool $brokenFix = false): ExportGate
    {
        $checksum = new class($canFix, $brokenFix) implements ChecksumModule
        {
            public function __construct(private bool $canFix, private bool $brokenFix) {}

            public function name(): string
            {
                return 'Toy sum';
            }

            public function supports(Bin $bin): bool
            {
                return true;
            }

            public function check(Bin $bin): array
            {
                $calc = array_sum(unpack('C*', substr($bin->data, 0, -1))) & 0xFF;
                $stored = ord($bin->data[$bin->size - 1]);

                return [['name' => 'Sum', 'start' => 0, 'end' => $bin->size - 1, 'stored' => dechex($stored), 'calculated' => dechex($calc), 'ok' => $stored === $calc]];
            }

            public function canFix(): bool
            {
                return $this->canFix;
            }

            public function fix(Bin $bin): Bin
            {
                $calc = array_sum(unpack('C*', substr($bin->data, 0, -1))) & 0xFF;

                return $bin->withPatch($bin->size - 1, chr($this->brokenFix ? $calc ^ 0xFF : $calc));
            }
        };

        $family = new class($module ? $checksum : null) implements EcuFamily
        {
            public function __construct(private ?ChecksumModule $module) {}

            public function id(): string
            {
                return 'toy';
            }

            public function detect(Bin $bin): ?array
            {
                return ['score' => 100, 'family' => 'Toy'];
            }

            public function findMaps(Bin $bin, array $ident): array
            {
                return [];
            }

            public function checksum(Bin $bin): ?ChecksumModule
            {
                return $this->module;
            }
        };

        return new ExportGate(new Analyzer([$family]));
    }
}
