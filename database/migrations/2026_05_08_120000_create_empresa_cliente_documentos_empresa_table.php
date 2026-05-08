<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_cliente_documentos_empresa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_cliente_id')->constrained('empresas_cliente')->cascadeOnDelete();
            $table->string('tipo_documento', 64);
            $table->string('nombre_original', 255);
            $table->string('ruta_archivo', 500);
            $table->string('formato', 10)->default('pdf');
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->timestamp('fecha_subida')->useCurrent();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['empresa_cliente_id', 'tipo_documento'], 'uniq_empresa_tipo_documento');
            $table->index(['empresa_cliente_id', 'fecha_subida'], 'idx_empresa_fecha_subida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_cliente_documentos_empresa');
    }
};

