<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultora_id')->constrained('empresas_consultoras')->cascadeOnDelete();
            $table->foreignId('empresa_cliente_id')->constrained('empresas_cliente')->cascadeOnDelete();
            $table->foreignId('creado_por_usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('asignado_a_colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();

            $table->string('tipo', 64);
            $table->string('nombre', 255);
            $table->text('descripcion')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('estado', 32)->default('pendiente'); // pendiente, en_proceso, vencido, completado
            $table->unsignedTinyInteger('progreso_pct')->default(0);

            $table->timestamp('completado_en')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();

            $table->index(['consultora_id', 'estado']);
            $table->index(['empresa_cliente_id', 'estado']);
            $table->index('fecha_vencimiento');
            $table->index('asignado_a_colaborador_id');
        });

        Schema::create('tareas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->cascadeOnDelete();
            $table->string('nombre', 255);
            $table->text('descripcion')->nullable();
            $table->string('estado', 32)->default('pendiente'); // pendiente, en_proceso, completada
            $table->foreignId('responsable_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('requiere_documento')->default(false);
            $table->timestamp('completada_en')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();

            $table->index(['tramite_id', 'orden']);
            $table->index('responsable_id');
        });

        Schema::create('tarea_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarea_id')->constrained('tareas')->cascadeOnDelete();
            $table->string('tipo', 64)->default('adjunto');
            $table->string('nombre_original');
            $table->string('ruta_archivo', 500);
            $table->string('formato', 16)->default('pdf');
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->foreignId('subido_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('fecha_subida')->useCurrent();

            $table->index('tarea_id');
        });

        Schema::create('tramite_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->cascadeOnDelete();
            $table->string('tipo', 64); // creacion, asignacion, tarea_completada, documento_subido, estado_cambio, cierre
            $table->string('titulo', 255);
            $table->text('descripcion')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamp('ocurrido_en')->useCurrent();

            $table->index(['tramite_id', 'ocurrido_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_eventos');
        Schema::dropIfExists('tarea_documentos');
        Schema::dropIfExists('tareas');
        Schema::dropIfExists('tramites');
    }
};
