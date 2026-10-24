<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sw')->nullable()->index();
            $table->string('family_id')->nullable();
            $table->string('source_filename')->nullable();
            $table->unsignedInteger('map_count')->default(0);
            $table->json('maps');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_definitions');
    }
};
