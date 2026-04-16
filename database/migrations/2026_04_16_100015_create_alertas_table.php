<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultora_id')->constrained('empresas_consultoras')->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas_cliente')->cascadeOnDelete();
            $table->foreignId('personal_id')->nullable()->constrained('personal')->cascadeOnDelete();
            $table->foreignId('colaborador_asignado')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('modulo', 32)->nullable();
            $table->string('nivel', 32)->default('normal');
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->boolean('resuelta')->default(false);
            $table->foreignId('resuelta_por')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('resuelta_en')->nullable();
            $table->boolean('generada_auto')->default(false);
            $table->timestamp('creado_en')->useCurrent();

            $table->index('consultora_id');
            $table->index('empresa_id');
            $table->index('personal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas');
    }
};
