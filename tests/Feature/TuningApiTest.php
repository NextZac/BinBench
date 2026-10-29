<?php

namespace Tests\Feature;

use App\Models\EcuFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticBin;
use Tests\TestCase;

class TuningApiTest extends TestCase
{
    use RefreshDatabase;

    private int $fileId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        config(['services.tuning_ai' => ['base_url' => null, 'api_key' => null, 'model' => null]]);

        // EDC15 with driver's wish, torque limiter, smoke limiter and boost target the classifier recognises
        $rpm = [1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500];
        $pedal = [0, 1000, 2000, 4000, 6000, 8000, 10000];
        $air = [3000, 5000, 7000, 9000, 11000];
        $bin = (new SyntheticBin(0x80000))->ascii(0x7FEF0, '1037351761')->ascii(0x10000, 'R4 2,0l EDC  ');
        $bin->layoutA(0x60000, $rpm, $pedal, fn (int $r, int $c): int => (int) ($pedal[$c] * 0.65));
        $bin->layoutA(0x61000, $rpm, [600, 800, 900, 1000, 1100], fn (int $r, int $c): int => 4500 + $c * 100);
        $bin->layoutA(0x62000, $rpm, $air, fn (int $r, int $c): int => (int) ($air[$c] * 0.6));
        $bin->layoutA(0x63000, [0, 2000, 4000, 6000, 8000], $rpm, fn (int $r, int $c): int => 1000 + $r * 350);

        $this->fileId = $this->postJson(route('files.store'), ['file' => UploadedFile::fake()->createWithContent('tdi.bin', $bin->data())])->json('id');
    }

    public function test_proposes_stage1_with_dyno_preview(): void
    {
        $response = $this->postJson(route('files.stage1', [$this->fileId, 'fuel_increase' => 10]));

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('aiConfigured', false)
            ->assertJsonPath('options.fuelIncrease', 10)
            ->assertJsonPath('dyno.engine.displacement', 2);
        $names = array_column($response->json('proposals'), 'name');
        $this->assertContains('Torque limiter (atm. pressure)', $names);
        $this->assertContains('Boost pressure target', $names);
        $this->assertGreaterThan($response->json('dyno.stock.peakPower.value'), $response->json('dyno.modified.peakPower.value'));
    }

    public function test_includes_copyable_prompt_with_summary_only(): void
    {
        $prompt = $this->postJson(route('files.stage1', $this->fileId))->assertOk()->json('prompt');

        $this->assertStringContainsString('Never suggest removing', $prompt);
        $this->assertStringContainsString('"software": "1037351761"', $prompt);
        $this->assertStringContainsString('"map": "Boost pressure target"', $prompt);
        $this->assertStringNotContainsString('"values"', $prompt);
    }

    public function test_role_overrides_change_the_proposal(): void
    {
        $roles = $this->postJson(route('files.stage1', $this->fileId))->json('roles');
        $this->assertNotNull($roles['smoke']['id']);

        $response = $this->postJson(route('files.stage1', [$this->fileId, 'roles' => ['smoke' => 'none']]));

        $response->assertOk()
            ->assertJsonPath('roles.smoke.id', null)
            ->assertJsonPath('roles.smoke.overridden', true);
        $this->assertNotContains('smoke', array_column($response->json('proposals'), 'role'));
        $this->assertStringContainsString('No smoke limiter found', implode(' ', $response->json('warnings')));
    }

    public function test_diff_reviews_a_modified_buffer(): void
    {
        $original = EcuFile::find($this->fileId)->bin()->data;
        // torque limiter (rpm x patm map at 0x61000) +40 %: the driver's wish then caps it
        $limiter = 0x61000 + (2 + 8 + 5) * 2;
        $raised = $original;
        for ($i = 0; $i < 40; $i++) {
            $raised = substr_replace($raised, pack('v', (int) (unpack('v', substr($original, $limiter + $i * 2, 2))[1] * 1.4)), $limiter + $i * 2, 2);
        }

        $response = $this->call('POST', route('files.diff', $this->fileId), [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $raised);

        $response->assertOk()
            ->assertJsonPath('maps.0.name', 'Torque limiter (atm. pressure)')
            ->assertJsonPath('maps.0.changedCells', 40)
            ->assertJsonPath('unmapped', [])
            ->assertJsonPath('review.verdict', 'check');
        $this->assertContains("Driver's wish caps the raised torque limit", array_column($response->json('review.findings'), 'title'));
    }

    public function test_diff_without_changes_has_no_verdict(): void
    {
        $this->postJson(route('files.diff', $this->fileId))
            ->assertOk()
            ->assertJsonPath('changedBytes', 0)
            ->assertJsonPath('review.verdict', 'none');
    }

    public function test_rejects_aggressive_options(): void
    {
        $this->postJson(route('files.stage1', [$this->fileId, 'fuel_increase' => 90]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fuel_increase');
    }

    public function test_explain_needs_a_configured_provider(): void
    {
        Http::fake();

        $this->postJson(route('files.stage1.explain', $this->fileId))->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_explain_sends_only_a_summary_to_the_provider(): void
    {
        config(['services.tuning_ai' => ['base_url' => 'https://api.deepseek.test/', 'api_key' => 'test-key', 'model' => 'deepseek-chat']]);
        Http::fake(['api.deepseek.test/*' => Http::response(['choices' => [['message' => ['content' => '## What changes']]]])]);

        $this->postJson(route('files.stage1.explain', $this->fileId))
            ->assertOk()
            ->assertJsonPath('explanation', '## What changes')
            ->assertJsonPath('model', 'deepseek-chat');

        Http::assertSent(function (Request $request): bool {
            $summary = json_decode($request['messages'][1]['content'], true);

            return $request->url() === 'https://api.deepseek.test/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request['model'] === 'deepseek-chat'
                && str_contains($request['messages'][0]['content'], 'Never suggest removing')
                && $summary['ecu']['software'] === '1037351761'
                && isset($summary['changes'][0]['before'])
                && ! isset($summary['changes'][0]['values']);
        });
    }

    public function test_explain_reports_provider_errors(): void
    {
        config(['services.tuning_ai' => ['base_url' => 'https://api.deepseek.test', 'api_key' => 'k', 'model' => 'deepseek-chat']]);
        Http::fake(['api.deepseek.test/*' => Http::response(['error' => ['message' => 'Insufficient balance']], 402)]);

        $this->postJson(route('files.stage1.explain', $this->fileId))
            ->assertStatus(502)
            ->assertJsonPath('message', fn (string $m): bool => str_starts_with($m, 'The AI provider did not answer'));
    }
}
