<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * AFP, CAJA y Ministerio se resguardan una vez por trabajador (sin ciclo mensual).
     */
    public function up(): void
    {
        DB::table('tipos_documento')
            ->whereIn('modulo', ['afp', 'caja', 'ministerio'])
            ->update(['es_periodico' => false]);
    }

    public function down(): void
    {
        // No se restauran flags anteriores: el catálogo podía haberse editar manualmente.
    }
};
