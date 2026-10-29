<?php

namespace Tests\Feature;

use App\Ecu\Maps\MapDef;
use App\Models\EcuFile;
use App\Models\MapDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticBin;
use Tests\TestCase;

class EcuFileApiTest extends TestCase
{
    use RefreshDatabase;

    private int $mapAddress;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    private function edc15Upload(): UploadedFile
    {
        $bin = new SyntheticBin(0x80000);
        $bin->ascii(0x7FEF0, '1037351761');
        $this->mapAddress = $bin->layoutA(0x74290,
            [0, 850, 1250, 1750, 2250, 2750, 3500, 4600],
            [0, 1000, 2000, 3000, 4000, 5000, 6000],
            fn (int $r, int $c): int => 1000 + $r * 100 + $c * 50);

        return UploadedFile::fake()->createWithContent('test_dde4.bin', $bin->data());
    }

    public function test_upload_identifies_and_finds_maps(): void
    {
        $response = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()]);

        $response->assertCreated()
            ->assertJsonPath('family_id', 'edc15')
            ->assertJsonPath('sw', '1037351761')
            ->assertJsonPath('maps.0.address', $this->mapAddress)
            ->assertJsonPath('maps.0.name', 'Boost pressure target');

        $file = EcuFile::first();
        Storage::disk('local')->assertExists($file->path);
        $this->get(route('files.raw', $file))->assertOk();
    }

    public function test_rejects_tiny_files(): void
    {
        $this->postJson(route('files.store'), ['file' => UploadedFile::fake()->createWithContent('x.bin', 'abc')])
            ->assertUnprocessable();
    }

    public function test_saves_version_and_downloads_it(): void
    {
        $id = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()])->json('id');
        $file = EcuFile::find($id);
        $modified = substr_replace($file->bin()->data, "\x10\x27", $this->mapAddress, 2);

        $version = $this->postJson(route('files.versions.store', $file), [
            'file' => UploadedFile::fake()->createWithContent('m.bin', $modified),
            'label' => 'Stage 1',
            'fix' => false,
            'changed_maps' => json_encode(['Boost pressure target']),
        ])->assertCreated()->assertJsonPath('label', 'Stage 1')->json();

        $download = $this->get(route('files.versions.download', [$file, $version['id']]));
        $download->assertOk();
        $this->assertSame($modified, $download->getContent());
    }

    public function test_rejects_version_with_different_size(): void
    {
        $id = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()])->json('id');

        $this->postJson(route('files.versions.store', $id), ['file' => UploadedFile::fake()->createWithContent('m.bin', str_repeat("\0", 2048))])
            ->assertUnprocessable();
    }

    public function test_dyno_reports_missing_limiter_maps(): void
    {
        $id = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()])->json('id');

        $this->postJson(route('files.dyno', $id))
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('stock', null);
    }

    public function test_dyno_compares_original_with_modified_buffer(): void
    {
        $id = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()])->json('id');
        $file = EcuFile::find($id);
        $limiter = (new MapDef('Torque limiter (IQ vs rpm)', $this->mapAddress, 1, 7, factor: 0.01, unit: 'mg/st', confidence: 0.6))->toArray();
        $limiter['x'] = ['count' => 7, 'values' => [1000, 1500, 2000, 2500, 3000, 3500, 4000], 'unit' => '1/min', 'name' => 'Engine speed'];
        $file->update(['maps' => [$limiter]]);
        $modified = substr_replace($file->bin()->data, pack('v', 2000), $this->mapAddress, 2);

        $response = $this->call('POST', route('files.dyno', [$file, 'displacement' => 1.9, 'cylinders' => 4]), [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $modified);

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('engine.displacement', 1.9)
            ->assertJsonPath('stock.fuel.0', 10)
            ->assertJsonPath('modified.fuel.0', 20)
            ->assertJsonPath('modified.limiter.0', 'Torque limiter');
    }

    public function test_dyno_rejects_buffer_with_different_size(): void
    {
        $id = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()])->json('id');

        $this->call('POST', route('files.dyno', $id), [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], str_repeat("\0", 2048))
            ->assertUnprocessable();
    }

    public function test_xdf_import_creates_definition_and_export_round_trips(): void
    {
        $id = $this->postJson(route('files.store'), ['file' => $this->edc15Upload()])->json('id');

        $xdf = '<?xml version="1.0"?><XDFFORMAT version="1.60"><XDFHEADER><deftitle>Test</deftitle><BASEOFFSET offset="0" subtract="0" />'
            .'<CATEGORY index="0x0" name="Custom" /></XDFHEADER>'
            .'<XDFTABLE uniqueid="0x1"><title>My boost map</title><CATEGORYMEM index="0" category="1" />'
            .'<XDFAXIS id="x"><EMBEDDEDDATA mmedtypeflags="0x02" mmedaddress="0x10" mmedelementsizebits="16" /><indexcount>4</indexcount><MATH equation="X"><VAR id="X" /></MATH></XDFAXIS>'
            .'<XDFAXIS id="y"><EMBEDDEDDATA mmedtypeflags="0x02" mmedaddress="0x20" mmedelementsizebits="16" /><indexcount>2</indexcount><MATH equation="X"><VAR id="X" /></MATH></XDFAXIS>'
            .'<XDFAXIS id="z"><EMBEDDEDDATA mmedtypeflags="0x02" mmedaddress="0x100" mmedelementsizebits="16" mmedrowcount="2" mmedcolcount="4" /><MATH equation="X*0.5"><VAR id="X" /></MATH></XDFAXIS>'
            .'</XDFTABLE></XDFFORMAT>';

        $this->postJson(route('files.xdf.import', $id), ['file' => UploadedFile::fake()->createWithContent('t.xdf', $xdf)])
            ->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('maps.0.name', 'My boost map')
            ->assertJsonPath('maps.0.factor', 0.5);

        $this->assertSame('1037351761', MapDefinition::first()->sw);

        $export = $this->get(route('files.xdf.export', $id));
        $export->assertOk();
        $this->assertStringContainsString('<title>My boost map</title>', $export->getContent());
        $this->assertStringContainsString('mmedaddress="0x100"', $export->getContent());
    }
}
