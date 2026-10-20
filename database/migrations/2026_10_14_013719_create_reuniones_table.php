<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reuniones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('titulo')->nullable();
            $table->string('estado')->index();
            $table->dateTime('iniciada_en');
            $table->dateTime('finalizada_en')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();
            $table->foreignId('creada_por')->constrained('users');
            $table->unsignedInteger('costo_audio_segundos')->default(0);
            $table->unsignedInteger('costo_tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reuniones');
    }
};
