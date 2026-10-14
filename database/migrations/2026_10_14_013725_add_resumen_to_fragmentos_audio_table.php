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
        Schema::table('fragmentos_audio', function (Blueprint $table) {
            // Tema y resumen corto de este tramo, para la línea de tiempo en vivo.
            $table->json('resumen')->nullable()->after('error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fragmentos_audio', function (Blueprint $table) {
            $table->dropColumn('resumen');
        });
    }
};
