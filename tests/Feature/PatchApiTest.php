<?php

namespace Tests\Feature;

use App\Ecu\Bin;
use App\Ecu\Patches\Dde4CruiseSwitchMultimap;
use App\Models\EcuFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\SyntheticDde4;
use Tests\TestCase;

class PatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    private function upload(string $data): EcuFile
    {
        $id = $this->postJson(route('files.store'), ['file' => UploadedFile::fake()->createWithContent('dde4.bin', $data)])
            ->assertCreated()
            ->json('id');

        return EcuFile::findOrFail($id);
    }

    /**
     * @param  array<int, array{address: int, hex: string}>  $writes
     */
    private function applyWrites(string $data, array $writes): string
    {
        foreach ($writes as $write) {
            $data = substr_replace($data, (string) hex2bin($write['hex']), $write['address'], strlen($write['hex']) / 2);
        }

        return $data;
    }

    private function postBuffer(string $route, EcuFile $file, string $buffer, array $extra = []): TestResponse
    {
        return $this->call('POST', route($route, [$file, ...$extra]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $buffer);
    }

    public function test_lists_patches_with_their_status_for_the_original_file(): void
    {
        $file = $this->upload(SyntheticDde4::build()->data());

        $this->postJson(route('files.patches.index', $file))
            ->assertOk()
            ->assertJsonPath('patches.0.id', 'dde4-multimap-2')
            ->assertJsonPath('patches.0.status', 'available')
            ->assertJsonPath('patches.1.id', 'dde4-multimap-3')
            ->assertJsonPath('patches.1.software', ['1037351632', '1037351761']);
    }

    public function test_install_returns_writes_and_maps_and_the_patched_buffer_reports_installed(): void
    {
        $stock = SyntheticDde4::build()->data();
        $file = $this->upload($stock);

        $response = $this->postBuffer('files.patches.install', $file, $stock, ['patch' => 'dde4-multimap-3'])->assertOk();
        $patched = $this->applyWrites($stock, $response->json('writes'));

        $this->assertContains('Map 3 · Smoke limiter 8×8 (replaces mrwBRA_KF + mrwBRDY_KF)', array_column($response->json('maps'), 'name'));
        $this->postBuffer('files.patches.index', $file, $patched)
            ->assertJsonPath('patches.1.status', 'installed')
            ->assertJsonPath('patches.0.status', 'unavailable');
        $this->postBuffer('files.patches.install', $file, $patched, ['patch' => 'dde4-multimap-3'])->assertUnprocessable();

        $removed = $this->postBuffer('files.patches.uninstall', $file, $patched, ['patch' => 'dde4-multimap-3'])->assertOk();
        $this->assertSame($stock, $this->applyWrites($patched, $removed->json('writes')));
    }

    public function test_rejects_unknown_patches_and_buffers_of_another_size(): void
    {
        $stock = SyntheticDde4::build()->data();
        $file = $this->upload($stock);

        $this->postBuffer('files.patches.install', $file, $stock, ['patch' => 'nope'])->assertNotFound();
        $this->postBuffer('files.patches.index', $file, substr($stock, 0, 0x40000))->assertUnprocessable();
    }

    public function test_an_uploaded_read_with_a_multimap_lists_the_map_tables(): void
    {
        $stock = new Bin(SyntheticDde4::build()->data());
        $patch = new Dde4CruiseSwitchMultimap;
        foreach ($patch->install($stock) as $write) {
            $stock = $stock->withPatch($write['address'], $write['bytes']);
        }

        $file = $this->upload($stock->data);

        $names = array_column($file->maps, 'name');
        $this->assertContains('Map 2 · Boost actuator duty limit (ldwGRmaxKF)', $names);
        $this->assertContains('Map 1 · Torque limiter normal (mrwBDBN_KL)', $names);
    }

    public function test_patches_of_another_users_file_are_forbidden(): void
    {
        $file = $this->upload(SyntheticDde4::build()->data());
        $this->actingAs(User::factory()->create());

        $this->postJson(route('files.patches.index', $file))->assertForbidden();
    }
}
