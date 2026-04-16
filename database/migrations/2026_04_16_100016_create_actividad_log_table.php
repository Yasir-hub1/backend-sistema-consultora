<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actividad_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->foreignId('consultora_id')->nullable()->constrained('empresas_consultoras')->cascadeOnDelete();
            $table->string('accion', 100);
            $table->string('modulo', 32)->nullable();
            $table->string('entidad', 50)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->text('descripcion')->nullable();

            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('datos_anteriores')->nullable();
                $table->jsonb('datos_nuevos')->nullable();
            } else {
                $table->json('datos_anteriores')->nullable();
                $table->json('datos_nuevos')->nullable();
            }

            $table->ipAddress('ip_origen')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index('usuario_id');
            $table->index('consultora_id');
            $table->index(['entidad', 'entidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actividad_log');
    }
};
