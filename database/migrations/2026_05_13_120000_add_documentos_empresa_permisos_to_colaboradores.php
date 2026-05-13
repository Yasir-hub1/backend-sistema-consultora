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
            $table->boolean('puede_gestionar_otros_documentos_empresa')->default(false);
            $table->boolean('puede_gestionar_documentos_legales_mi_empresa')->default(false);
        });

        $colaboradorIds = DB::table('colaboradores')->pluck('id');
        foreach ($colaboradorIds as $colaboradorId) {
            $col = DB::table('colaboradores')->where('id', $colaboradorId)->first();
            $perms = DB::table('colaborador_permisos')->where('colaborador_id', $colaboradorId)->get();

            $otros = $perms->contains(
                fn ($p) => (bool) $p->puede_registrar_personal
                    || (bool) $p->puede_editar_personal
                    || (bool) $p->puede_subir_documentos
            );

            $legales = (bool) $col->puede_editar_empresa_cliente
                || $otros
                || $perms->contains(
                    fn ($p) => (bool) $p->puede_registrar_personal || (bool) $p->puede_editar_personal
                );

            DB::table('colaboradores')->where('id', $colaboradorId)->update([
                'puede_gestionar_otros_documentos_empresa' => $otros,
                'puede_gestionar_documentos_legales_mi_empresa' => $legales,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn([
                'puede_gestionar_otros_documentos_empresa',
                'puede_gestionar_documentos_legales_mi_empresa',
            ]);
        });
    }
};
