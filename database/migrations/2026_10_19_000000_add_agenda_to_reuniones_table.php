<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            // Puntos a tratar, en orden: se arman antes de empezar y se pueden ajustar durante la reunión.
            $table->json('agenda')->nullable()->after('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            $table->dropColumn('agenda');
        });
    }
};
