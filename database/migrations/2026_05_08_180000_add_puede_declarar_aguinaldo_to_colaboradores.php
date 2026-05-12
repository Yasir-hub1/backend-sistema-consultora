<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->boolean('puede_declarar_aguinaldo')->default(false);
        });

        $ids = DB::table('colaborador_permisos')
            ->where('puede_gestionar_modulo', true)
            ->distinct()
            ->pluck('colaborador_id');

        if ($ids->isNotEmpty()) {
            DB::table('colaboradores')->whereIn('id', $ids)->update(['puede_declarar_aguinaldo' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('puede_declarar_aguinaldo');
        });
    }
};
