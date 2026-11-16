<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember which file an XDF was imported for, so it applies even when the read has no
     * software number to match on.
     */
    public function up(): void
    {
        Schema::table('map_definitions', function (Blueprint $table) {
            $table->foreignId('ecu_file_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('map_definitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ecu_file_id');
        });
    }
};
