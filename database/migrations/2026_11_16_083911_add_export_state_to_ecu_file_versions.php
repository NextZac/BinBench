<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versions record whether their checksums were verified at export, why not if not, and what
     * the server found changed against the original.
     */
    public function up(): void
    {
        Schema::table('ecu_file_versions', function (Blueprint $table) {
            $table->string('export_state', 20)->default('unverified')->after('checksum_fixed');
            $table->text('unverified_reason')->nullable()->after('export_state');
            $table->json('changes')->nullable()->after('changed_maps');
        });

        // versions saved before the gate: verified only when their stored report says every block was valid
        foreach (DB::table('ecu_file_versions')->get(['id', 'checksums']) as $version) {
            $ok = (json_decode((string) $version->checksums, true)['ok'] ?? null) === true;
            DB::table('ecu_file_versions')->where('id', $version->id)->update([
                'export_state' => $ok ? 'verified' : 'unverified',
                'unverified_reason' => $ok ? null : 'Saved before checksum verification at export was introduced.',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ecu_file_versions', function (Blueprint $table) {
            $table->dropColumn(['export_state', 'unverified_reason', 'changes']);
        });
    }
};
