<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EcuFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\SyntheticBin;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $mapAddress;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function upload(): EcuFile
    {
        $bin = new SyntheticBin(0x80000);
        $bin->ascii(0x7FEF0, '1037351761');
        $this->mapAddress = $bin->layoutA(0x74290,
            [0, 850, 1250, 1750, 2250, 2750, 3500, 4600],
            [0, 1000, 2000, 3000, 4000, 5000, 6000],
            fn (int $r, int $c): int => 1000 + $r * 100 + $c * 50);
        $id = $this->postJson(route('files.store'), ['file' => UploadedFile::fake()->createWithContent('dde4.bin', $bin->data())])->json('id');

        return EcuFile::find($id);
    }

    public function test_records_the_life_of_a_file(): void
    {
        $file = $this->upload();
        $modified = substr_replace($file->bin()->data, "\x10\x27", $this->mapAddress, 2);
        $versionId = $this->postJson(route('files.versions.store', $file), [
            'file' => UploadedFile::fake()->createWithContent('m.bin', $modified),
            'label' => 'Stage 1',
            'acknowledge_unverified' => true,
        ])->assertCreated()->json('id');
        $this->get(route('files.versions.download', [$file, $versionId]))->assertOk();
        $this->patchJson(route('files.update', $file), ['name' => 'Client 320d'])->assertOk();

        $trail = $this->getJson(route('files.audit', $file))->assertOk()->json();

        $this->assertSame(['file.updated', 'version.downloaded', 'version.saved', 'file.uploaded'], array_column($trail, 'action'));
        $saved = $trail[2]['meta'];
        $this->assertSame('unverified', $saved['state']);
        $this->assertTrue($saved['acknowledgedUnverified']);
        $this->assertSame(2, $saved['changedBytes']);
        $this->assertSame(['Boost pressure target'], $saved['maps']);
        $this->assertSame(['name'], $trail[0]['meta']['fields']);

        $this->deleteJson(route('files.destroy', $file))->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['ecu_file_id' => $file->id, 'action' => 'file.deleted', 'user_id' => $this->user->id]);
        $this->assertSame(5, AuditLog::where('ecu_file_id', $file->id)->count());
    }

    public function test_audit_trail_is_private_to_the_owner(): void
    {
        $file = $this->upload();
        $this->actingAs(User::factory()->create());

        $this->getJson(route('files.audit', $file))->assertForbidden();
    }

    public function test_records_failed_logins(): void
    {
        auth()->logout();

        $this->postJson(route('auth.login'), ['email' => 'Someone@Example.com', 'password' => 'wrong'])->assertUnprocessable();

        $entry = AuditLog::where('action', 'auth.login_failed')->sole();
        $this->assertSame('someone@example.com', $entry->meta['email']);
        $this->assertNull($entry->user_id);
    }

    public function test_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = AuditLog::record('file.uploaded');

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'nothing']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $entry = AuditLog::record('file.uploaded');

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
