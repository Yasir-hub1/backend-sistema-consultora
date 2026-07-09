<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('tramites')
            ->whereNotNull('asignado_a_colaborador_id')
            ->get(['id', 'asignado_a_colaborador_id']);

        foreach ($rows as $row) {
            DB::table('tramite_colaboradores')->insertOrIgnore([
                'tramite_id' => $row->id,
                'colaborador_id' => $row->asignado_a_colaborador_id,
                'asignado_en' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No revertir datos de backfill.
    }
};
