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
        Schema::table('reuniones', function (Blueprint $table) {
            // Conversación del chat con Jarvis durante esta reunión.
            $table->string('conversacion_id', 36)->nullable()->after('creada_por');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            $table->dropColumn('conversacion_id');
        });
    }
};
