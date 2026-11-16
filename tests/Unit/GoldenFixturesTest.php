<?php

namespace Tests\Unit;

use App\Ecu\Analyzer;
use App\Ecu\Bin;
use App\Ecu\Diff\BinDiff;
use App\Ecu\Export\ExportBlocked;
use App\Ecu\Export\ExportGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests against real dumps listed in test-fixtures/manifest.json.
 *
 * The dumps are not in the repository (see test-fixtures/README.md). Without a manifest these
 * tests are skipped; with one, every listed file must be present and intact.
 */
class GoldenFixturesTest extends TestCase
{
    private const DIR = __DIR__.'/../../test-fixtures';

    /** Bytes a checksum correction may write when one calibration byte changed. */
    private const DEFAULT_MAX_CORRECTION = 1024;

    /**
     * @return array<string, array{0: ?array<string, mixed>}>
     */
    public static function fixtures(): array
    {
        $manifest = self::DIR.'/manifest.json';
        if (! is_file($manifest)) {
            return ['no manifest' => [null]];
        }
        $cases = [];
        foreach (json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['fixtures'] as $fixture) {
            $cases[$fixture['file']] = [$fixture];
        }

        return $cases;
    }

    #[DataProvider('fixtures')]
    public function test_identification(?array $fixture): void
    {
        $bin = $this->load($fixture);

        $found = (new Analyzer)->identify($bin);

        $this->assertSame($fixture['family'], $found['family']->id());
        $this->assertSame($fixture['sw'], $found['ident']['sw'] ?? null);
    }

    #[DataProvider('fixtures')]
    public function test_checksums_report_as_recorded(?array $fixture): void
    {
        $bin = $this->load($fixture);
        $analyzer = new Analyzer;

        $report = $analyzer->checksumReport($analyzer->family($fixture['family'])->checksum($bin), $bin);

        $this->assertSame($fixture['checksum']['module'], $report['module']);
        $this->assertSame($fixture['checksum']['ok'], $report['ok'], json_encode($report['error'] ?? array_filter($report['blocks'], fn (array $b): bool => ! $b['ok'])));
        if (isset($fixture['checksum']['blocks'])) {
            $this->assertCount($fixture['checksum']['blocks'], $report['blocks']);
        }
    }

    #[DataProvider('fixtures')]
    public function test_export_of_the_untouched_file_changes_no_byte(?array $fixture): void
    {
        $bin = $this->load($fixture);
        $gate = new ExportGate;

        if ($fixture['checksum']['ok'] !== true) {
            // a file BinBench cannot verify must be blocked, and pass through unchanged once acknowledged
            try {
                $gate->prepare($bin, $bin, $fixture['family'], []);
                $this->fail('Expected the export to be blocked.');
            } catch (ExportBlocked) {
                $export = $gate->prepare($bin, $bin, $fixture['family'], [], acknowledgeUnverified: true);
                $this->assertSame(ExportGate::UNVERIFIED, $export['state']);
                $this->assertSame($fixture['sha1'], sha1($export['bin']->data));
            }

            return;
        }

        $export = $gate->prepare($bin, $bin, $fixture['family'], []);

        $this->assertSame(ExportGate::VERIFIED, $export['state']);
        $this->assertFalse($export['corrected']);
        $this->assertSame($fixture['sha1'], sha1($export['bin']->data));
    }

    #[DataProvider('fixtures')]
    public function test_changed_byte_is_corrected_and_only_checksums_move(?array $fixture): void
    {
        $bin = $this->load($fixture);
        if (! isset($fixture['mutate'])) {
            $this->markTestSkipped('No "mutate" offset recorded for this fixture.');
        }
        $at = (int) hexdec($fixture['mutate']);
        $modified = $bin->withPatch($at, chr(ord($bin->data[$at]) ^ 0x01));
        $analyzer = new Analyzer;
        $family = $analyzer->family($fixture['family']);

        $this->assertFalse($analyzer->checksumReport($family->checksum($modified), $modified)['ok'], 'Changing a calibration byte must invalidate a checksum.');

        $export = (new ExportGate)->prepare($bin, $modified, $fixture['family'], []);

        $this->assertSame(ExportGate::VERIFIED, $export['state']);
        $this->assertTrue($export['corrected']);
        $this->assertSame($modified->data[$at], $export['bin']->data[$at], 'The edit itself must survive the correction.');
        // every byte the correction wrote is accounted for, and it is a small, bounded set
        $written = BinDiff::changedRuns($modified, $export['bin']);
        $bytes = array_sum(array_map(fn (array $r): int => $r[1] - $r[0], $written));
        $this->assertSame($bytes, $export['changes']['correction']['bytes']);
        $this->assertGreaterThan(0, $bytes);
        $this->assertLessThanOrEqual($fixture['max_correction_bytes'] ?? self::DEFAULT_MAX_CORRECTION, $bytes);
        foreach ($written as [$start, $end]) {
            $this->assertFalse($start <= $at && $at < $end, 'The correction overwrote the edited byte.');
        }
        // a second run finds nothing left to do
        $again = (new ExportGate)->prepare($bin, $export['bin'], $fixture['family'], []);
        $this->assertFalse($again['corrected']);
        $this->assertSame($export['bin']->data, $again['bin']->data);
    }

    #[DataProvider('fixtures')]
    public function test_correction_reproduces_the_reference_file(?array $fixture): void
    {
        $bin = $this->load($fixture);
        if (! isset($fixture['corrects_to'])) {
            $this->markTestSkipped('No "corrects_to" reference recorded for this fixture.');
        }
        $reference = Bin::fromFile(self::DIR.'/'.$fixture['corrects_to']);

        $export = (new ExportGate)->prepare($bin, $bin, $fixture['family'], []);

        $this->assertSame(sha1($reference->data), sha1($export['bin']->data), 'Corrected output differs from the reference tool.');
    }

    #[DataProvider('fixtures')]
    public function test_known_maps_are_found(?array $fixture): void
    {
        $bin = $this->load($fixture);
        if (empty($fixture['maps'])) {
            $this->markTestSkipped('No maps recorded for this fixture.');
        }
        $byAddress = [];
        foreach ((new Analyzer)->analyze($bin)['maps'] as $map) {
            $byAddress[$map->address] = $map;
        }

        foreach ($fixture['maps'] as $expected) {
            $map = $byAddress[(int) hexdec($expected['address'])] ?? null;
            $this->assertNotNull($map, "No map found at {$expected['address']} ({$expected['name']}).");
            $this->assertSame([$expected['rows'], $expected['cols']], [$map->rows, $map->cols], "Size of {$expected['name']} at {$expected['address']}.");
            $this->assertStringStartsWith($expected['name'], $map->name, "Name of the map at {$expected['address']}.");
        }
    }

    /**
     * @param  ?array<string, mixed>  $fixture
     */
    private function load(?array $fixture): Bin
    {
        if ($fixture === null) {
            $this->markTestSkipped('No test-fixtures/manifest.json: add real dumps to run the golden tests (see test-fixtures/README.md).');
        }
        $path = self::DIR.'/'.$fixture['file'];
        $this->assertFileExists($path, 'Listed in the manifest but missing.');
        $bin = Bin::fromFile($path);
        $this->assertSame($fixture['sha1'], sha1($bin->data), "{$fixture['file']} does not match its recorded hash.");

        return $bin;
    }
}
