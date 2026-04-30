<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declaraciones_mensuales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_cliente_id')->constrained('empresas_cliente')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');

            $table->string('nombre_archivo');
            $table->string('nombre_original');
            $table->string('ruta_archivo', 500);
            $table->string('formato', 12);
            $table->unsignedBigInteger('tamano_bytes')->nullable();

            $table->foreignId('subido_por')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('fecha_subida')->useCurrent();

            $table->timestamps();

            $table->unique(['empresa_cliente_id', 'anio', 'mes']);
            $table->index(['empresa_cliente_id', 'anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declaraciones_mensuales');
    }
};
