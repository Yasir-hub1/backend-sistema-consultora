<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas_consultoras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('registrada_por')->constrained('administradores');

            $table->string('razon_social', 200);
            $table->string('nombre_comercial', 150)->nullable();
            $table->string('nit', 30)->unique();

            $table->string('representante_nombres', 100);
            $table->string('representante_apellidos', 100);
            $table->string('representante_ci', 20)->nullable();
            $table->string('representante_ext_ci', 2)->nullable();

            $table->string('correo_principal', 150);
            $table->string('telefono', 20)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('departamento', 100)->nullable();
            $table->text('direccion')->nullable();

            $table->string('estado', 32)->default('pendiente_activacion');
            $table->boolean('configuracion_completa')->default(false);
            $table->date('fecha_registro')->useCurrent();
            $table->text('observaciones')->nullable();

            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index('estado');
            $table->index('nit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas_consultoras');
    }
};
