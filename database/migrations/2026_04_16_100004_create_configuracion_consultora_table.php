<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_consultora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultora_id')->unique()->constrained('empresas_consultoras')->cascadeOnDelete();

            $table->string('logo_url', 500)->nullable();
            $table->string('color_marca', 7)->nullable();
            $table->string('correo_soporte', 150)->nullable();
            $table->string('telefono_soporte', 20)->nullable();

            $table->string('banco', 100)->nullable();
            $table->string('nro_cuenta', 50)->nullable();
            $table->string('tipo_cuenta', 32)->nullable();
            $table->string('titular_cuenta', 200)->nullable();
            $table->char('moneda', 3)->default('BOB');

            $table->string('banco_alt', 100)->nullable();
            $table->string('nro_cuenta_alt', 50)->nullable();
            $table->string('tipo_cuenta_alt', 32)->nullable();
            $table->string('titular_cuenta_alt', 200)->nullable();
            $table->char('moneda_alt', 3)->nullable();

            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('plantilla_entrega')->default(DB::raw("'[]'::jsonb"));
            } else {
                $table->json('plantilla_entrega')->default(json_encode([]));
            }

            $table->boolean('notif_correo_alertas')->default(true);
            $table->smallInteger('notif_dias_anticipacion')->default(3);

            $table->timestamp('actualizado_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion_consultora');
    }
};
