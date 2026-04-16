<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaborador_empresa_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas_cliente')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->foreignId('asignado_por')->nullable()->constrained('empresas_consultoras');
            $table->timestamp('asignado_en')->useCurrent();
            $table->timestamp('removido_en')->nullable();

            $table->unique(['colaborador_id', 'empresa_id']);
            $table->index('colaborador_id');
            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_empresa_cliente');
    }
};
