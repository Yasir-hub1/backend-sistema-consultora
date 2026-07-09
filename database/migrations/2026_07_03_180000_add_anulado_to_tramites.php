<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            $table->boolean('anulado')->default(false)->after('recurrencia_anulada_en');
            $table->timestamp('anulado_en')->nullable()->after('anulado');
        });

        DB::table('tramites')
            ->where('es_recurrente', true)
            ->where('recurrencia_activa', false)
            ->update([
                'anulado' => true,
                'anulado_en' => DB::raw('recurrencia_anulada_en'),
            ]);
    }

    public function down(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            $table->dropColumn(['anulado', 'anulado_en']);
        });
    }
};
