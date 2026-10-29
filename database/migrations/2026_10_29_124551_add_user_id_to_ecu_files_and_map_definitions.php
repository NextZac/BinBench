<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files and XDF definitions belong to the user who uploaded them. Existing rows stay without
     * an owner until assigned with `php artisan binbench:claim-files`.
     */
    public function up(): void
    {
        Schema::table('ecu_files', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        Schema::table('map_definitions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ecu_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('map_definitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
