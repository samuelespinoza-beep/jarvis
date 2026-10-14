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
            $table->dateTime('pausada_en')->nullable()->after('iniciada_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reuniones', function (Blueprint $table) {
            $table->dropColumn('pausada_en');
        });
    }
};
