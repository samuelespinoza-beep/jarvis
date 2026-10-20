<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumenes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reunion_id')->constrained('reuniones')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('modelo');
            $table->json('contenido');
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['reunion_id', 'version']);
        });

        Schema::table('reuniones', function (Blueprint $table) {
            $table->dateTime('transcripcion_lista_en')->nullable()->after('duracion_segundos');
        });
    }

    public function down(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            $table->dropColumn('transcripcion_lista_en');
        });

        Schema::dropIfExists('resumenes');
    }
};
