<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuracion_consultora', function (Blueprint $table) {
            $table->foreignId('institucion_financiera_id')
                ->nullable()
                ->after('telefono_soporte')
                ->constrained('instituciones_financieras')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('configuracion_consultora', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institucion_financiera_id');
        });
    }
};
