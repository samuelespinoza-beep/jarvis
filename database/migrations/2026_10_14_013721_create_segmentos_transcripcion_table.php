<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segmentos_transcripcion', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reunion_id')->constrained('reuniones')->cascadeOnDelete();
            $table->foreignUlid('fragmento_id')->constrained('fragmentos_audio')->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->string('hablante')->nullable();
            $table->decimal('inicio_segundos', 10, 2);
            $table->decimal('fin_segundos', 10, 2);
            $table->text('texto');

            $table->index(['reunion_id', 'inicio_segundos']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segmentos_transcripcion');
    }
};
