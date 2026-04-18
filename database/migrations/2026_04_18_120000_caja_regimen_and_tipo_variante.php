<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->string('caja_variante', 20)->nullable()->after('modulo');
        });

        Schema::table('personal_caja', function (Blueprint $table) {
            $table->string('regimen_caja', 20)->nullable()->after('personal_id');
        });
    }

    public function down(): void
    {
        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->dropColumn('caja_variante');
        });

        Schema::table('personal_caja', function (Blueprint $table) {
            $table->dropColumn('regimen_caja');
        });
    }
};
