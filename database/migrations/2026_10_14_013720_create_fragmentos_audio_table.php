<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fragmentos_audio', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reunion_id')->constrained('reuniones')->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->string('ruta_archivo');
            $table->string('mime');
            $table->unsignedInteger('tamano_bytes');
            $table->unsignedInteger('duracion_segundos');
            $table->string('estado')->index();
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['reunion_id', 'orden']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fragmentos_audio');
    }
};
