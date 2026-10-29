<?php

namespace Tests\Feature;

use App\Models\EcuFile;
use App\Models\MapDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticBin;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['binbench.registration' => true, 'binbench.invite_code' => null]);
    }

    public function test_guests_cannot_reach_files_or_definitions(): void
    {
        $this->getJson(route('files.index'))->assertUnauthorized();
        $this->postJson(route('files.store'))->assertUnauthorized();
        $this->getJson(route('definitions.index'))->assertUnauthorized();
        $this->get(route('files.raw', 1))->assertUnauthorized();
    }

    public function test_users_only_see_and_change_their_own_files(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->actingAs($owner)->postJson(route('files.store'), ['file' => $this->upload()])->assertCreated()->json('id');

        $this->actingAs($other)->getJson(route('files.index'))->assertOk()->assertJsonCount(0);
        $this->getJson(route('files.show', $id))->assertForbidden();
        $this->get(route('files.raw', $id))->assertForbidden();
        $this->postJson(route('files.stage1', $id))->assertForbidden();
        $this->deleteJson(route('files.destroy', $id))->assertForbidden();

        $this->actingAs($owner)->getJson(route('files.index'))->assertOk()->assertJsonPath('0.id', $id);
        $this->assertSame($owner->id, EcuFile::find($id)->user_id);
    }

    public function test_definitions_are_private(): void
    {
        $owner = User::factory()->create();
        $definition = $owner->mapDefinitions()->create(['name' => 'Mine', 'sw' => '1037351761', 'map_count' => 0, 'maps' => []]);

        $this->actingAs(User::factory()->create())->getJson(route('definitions.index'))->assertJsonCount(0);
        $this->deleteJson(route('definitions.destroy', $definition))->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('definitions.destroy', $definition))->assertNoContent();
    }

    public function test_register_login_and_logout(): void
    {
        $this->postJson(route('auth.register'), [
            'name' => 'Seb', 'email' => 'seb@example.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ])->assertOk()->assertJsonPath('user.email', 'seb@example.test');
        $this->assertAuthenticated();

        $this->postJson(route('auth.logout'))->assertOk()->assertJsonPath('user', null);
        $this->assertGuest();

        $this->postJson(route('auth.login'), ['email' => 'seb@example.test', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(route('auth.login'), ['email' => 'seb@example.test', 'password' => 'correct-horse-battery'])
            ->assertOk()->assertJsonPath('user.name', 'Seb');
        $this->getJson(route('auth.show'))->assertJsonPath('user.email', 'seb@example.test');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('auth.login'), ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->postJson(route('auth.login'), ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    }

    public function test_registration_can_require_an_invite_code_or_be_closed(): void
    {
        config(['binbench.invite_code' => 'drift-day']);
        $data = ['name' => 'A', 'email' => 'a@example.test', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'];

        $this->getJson(route('auth.show'))->assertJsonPath('inviteRequired', true);
        $this->postJson(route('auth.register'), [...$data, 'invite_code' => 'nope'])->assertJsonValidationErrors('invite_code');
        $this->postJson(route('auth.register'), [...$data, 'invite_code' => 'drift-day'])->assertOk();

        auth()->logout();
        config(['binbench.registration' => false]);
        $this->postJson(route('auth.register'), [...$data, 'email' => 'b@example.test', 'invite_code' => 'drift-day'])->assertForbidden();
    }

    public function test_claim_command_assigns_files_without_owner(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $file = EcuFile::create(['name' => 'old', 'original_filename' => 'old.bin', 'size' => 1, 'sha1' => 'x', 'path' => 'bins/old.bin']);
        $definition = MapDefinition::create(['name' => 'old', 'sw' => '1', 'map_count' => 0, 'maps' => []]);

        $this->artisan('binbench:claim-files', ['email' => 'owner@example.test'])->assertSuccessful();

        $this->assertSame($user->id, $file->fresh()->user_id);
        $this->assertSame($user->id, $definition->fresh()->user_id);
        $this->artisan('binbench:claim-files', ['email' => 'missing@example.test'])->assertFailed();
    }

    private function upload(): UploadedFile
    {
        $bin = (new SyntheticBin(0x80000))->ascii(0x7FEF0, '1037351761');

        return UploadedFile::fake()->createWithContent('a.bin', $bin->data());
    }
}
