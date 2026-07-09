<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramite_recordatorios_enviados', function (Blueprint $table) {
            $table->json('alerta_ids')->nullable()->after('tipo');
            $table->unsignedSmallInteger('reintentos')->default(0)->after('alerta_ids');
            $table->index(['tramite_id', 'enviado_en']);
        });
    }

    public function down(): void
    {
        Schema::table('tramite_recordatorios_enviados', function (Blueprint $table) {
            $table->dropIndex(['tramite_id', 'enviado_en']);
            $table->dropColumn(['alerta_ids', 'reintentos']);
        });
    }
};
