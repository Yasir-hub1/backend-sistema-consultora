<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_cliente_otros_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_cliente_id')->constrained('empresas_cliente')->cascadeOnDelete();
            $table->text('descripcion')->nullable();
            $table->string('nombre_original', 255);
            $table->string('ruta_archivo', 500);
            $table->string('formato', 10)->default('pdf');
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('fecha_subida')->useCurrent();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index(['empresa_cliente_id', 'fecha_subida'], 'idx_empresa_otros_docs_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_cliente_otros_documentos');
    }
};
