<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_usuario', 80)->unique();
            $table->string('correo', 150)->unique();
            $table->string('contrasena_hash');
            $table->string('tipo', 32);
            $table->string('estado', 32)->default('pendiente_activacion');
            $table->boolean('verificado')->default(false);
            $table->string('token_activacion')->nullable();
            $table->timestamp('token_activacion_exp')->nullable();
            $table->string('token_reset')->nullable();
            $table->timestamp('token_reset_exp')->nullable();
            $table->timestamp('ultimo_acceso')->nullable();
            $table->ipAddress('ip_ultimo_acceso')->nullable();
            $table->smallInteger('intentos_fallidos')->default(0);
            $table->timestamp('bloqueado_hasta')->nullable();
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index('tipo');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
