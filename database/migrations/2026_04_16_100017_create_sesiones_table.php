<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones del dominio Consult-360 (JWT / tokens).
 * No confundir con la tabla "sessions" del driver de sesión web de Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->ipAddress('ip_origen')->nullable();
            $table->string('agente_usuario', 500)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('expira_en');
            $table->timestamp('cerrado_en')->nullable();

            $table->index('usuario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones');
    }
};
