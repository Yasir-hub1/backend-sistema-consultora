<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaborador_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('modulo', 32);

            $table->boolean('puede_ver')->default(true);
            $table->boolean('puede_registrar_personal')->default(false);
            $table->boolean('puede_editar_personal')->default(false);
            $table->boolean('puede_subir_documentos')->default(false);
            $table->boolean('puede_eliminar_documentos')->default(false);
            $table->boolean('puede_gestionar_modulo')->default(false);
            $table->boolean('puede_exportar_reportes')->default(false);
            $table->boolean('puede_invitar_empresa')->default(false);

            $table->foreignId('configurado_por')->nullable()->constrained('empresas_consultoras');
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['colaborador_id', 'modulo']);
            $table->index('colaborador_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_permisos');
    }
};
