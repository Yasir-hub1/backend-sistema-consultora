<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultora_id')->constrained('empresas_consultoras')->cascadeOnDelete();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios')->cascadeOnDelete();

            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('ci', 20)->nullable();
            $table->string('extension_ci', 2)->nullable();
            $table->string('telefono', 20)->nullable();

            $table->string('cargo', 32);
            $table->date('fecha_ingreso')->nullable();
            $table->string('estado', 32)->default('activo');
            $table->text('observaciones')->nullable();

            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->index('consultora_id');
            $table->index('cargo');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
