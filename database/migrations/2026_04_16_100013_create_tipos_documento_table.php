<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento', function (Blueprint $table) {
            $table->id();
            $table->string('modulo', 32);
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('obligatorio')->default(false);
            $table->boolean('es_periodico')->default(false);
            $table->string('formatos_permitidos', 100)->default('pdf,xlsx,docx,jpg,png');
            $table->smallInteger('tamano_maximo_mb')->default(10);
            $table->boolean('activo')->default(true);
            $table->smallInteger('orden_visualizacion')->default(0);
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['modulo', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_documento');
    }
};
