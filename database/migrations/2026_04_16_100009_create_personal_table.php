<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas_cliente')->restrictOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('colaboradores')->nullOnDelete();

            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('ci', 20);
            $table->string('extension_ci', 2)->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('genero', 32)->nullable();
            $table->string('estado_civil', 32)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('correo', 150)->nullable();
            $table->text('direccion')->nullable();
            $table->string('nivel_educacion', 32)->nullable();
            $table->string('profesion', 150)->nullable();

            $table->string('cargo', 150);
            $table->date('fecha_ingreso');
            $table->date('fecha_egreso')->nullable();
            $table->string('tipo_contrato', 32)->nullable();
            $table->decimal('salario_mensual', 10, 2)->nullable();
            $table->string('modalidad', 32)->default('presencial');
            $table->string('estado', 32)->default('activo');
            $table->text('observaciones')->nullable();

            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['empresa_id', 'ci']);
            $table->index('empresa_id');
            $table->index('ci');
            $table->index('estado');
            $table->index('registrado_por');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal');
    }
};
