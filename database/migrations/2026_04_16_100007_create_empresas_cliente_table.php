<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultora_id')->constrained('empresas_consultoras')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->unique()->constrained('usuarios')->nullOnDelete();
            $table->foreignId('registrada_por')->nullable()->constrained('colaboradores')->nullOnDelete();

            $table->string('nombre', 200);
            $table->string('nit', 30);
            $table->string('razon_social', 200)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('departamento', 100)->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('correo_empresa', 150)->nullable();
            $table->string('actividad_economica', 200)->nullable();
            $table->string('matricula_comercio', 50)->nullable();

            $table->string('rep_legal_nombres', 100)->nullable();
            $table->string('rep_legal_apellidos', 100)->nullable();
            $table->string('rep_legal_ci', 20)->nullable();
            $table->string('rep_legal_ext_ci', 2)->nullable();

            $table->string('estado', 32)->default('activo');
            $table->date('fecha_registro')->useCurrent();
            $table->text('observaciones')->nullable();

            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['consultora_id', 'nit']);
            $table->index('consultora_id');
            $table->index('nit');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas_cliente');
    }
};
