<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_id')->constrained('personal')->cascadeOnDelete();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->restrictOnDelete();
            $table->string('modulo', 32);

            $table->string('nombre_archivo');
            $table->string('nombre_original');
            $table->string('ruta_archivo', 500);
            $table->string('formato', 10);
            $table->unsignedBigInteger('tamano_bytes')->nullable();

            $table->string('periodo', 20)->nullable();
            $table->date('fecha_documento')->nullable();

            $table->text('observacion')->nullable();
            $table->boolean('es_vigente')->default(true);
            $table->foreignId('subido_por')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('fecha_subida')->useCurrent();

            $table->boolean('eliminado')->default(false);
            $table->foreignId('eliminado_por')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('eliminado_en')->nullable();

            $table->index('personal_id');
            $table->index('modulo');
            $table->index('tipo_documento_id');
            $table->index('periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
    }
};
