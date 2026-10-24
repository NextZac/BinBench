<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecu_file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecu_file_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('path');
            $table->char('sha1', 40);
            $table->boolean('checksum_fixed')->default(false);
            $table->json('checksums')->nullable();
            $table->json('changed_maps')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecu_file_versions');
    }
};
