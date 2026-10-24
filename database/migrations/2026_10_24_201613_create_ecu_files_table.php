<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecu_files', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('original_filename');
            $table->unsignedInteger('size');
            $table->char('sha1', 40)->index();
            $table->string('path');
            $table->string('family_id')->nullable();
            $table->string('family')->nullable();
            $table->string('sw')->nullable()->index();
            $table->json('ident')->nullable();
            $table->json('maps')->nullable();
            $table->json('checksums')->nullable();
            $table->unsignedInteger('map_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecu_files');
    }
};
