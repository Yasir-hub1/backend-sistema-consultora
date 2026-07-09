<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            $table->boolean('es_recurrente')->default(false)->after('fecha_vencimiento');
            $table->string('frecuencia', 32)->nullable()->after('es_recurrente');
            $table->unsignedTinyInteger('dia_vencimiento_mes')->nullable()->after('frecuencia');
            $table->time('hora_vencimiento')->default('18:00:00')->after('dia_vencimiento_mes');
            $table->string('periodo_actual', 7)->nullable()->after('hora_vencimiento');
            $table->boolean('notificar_cada_periodo')->default(true)->after('periodo_actual');

            $table->index('es_recurrente');
            $table->index('periodo_actual');
        });

        Schema::table('tarea_documentos', function (Blueprint $table) {
            $table->string('periodo', 7)->nullable()->after('tarea_id');
            $table->index('periodo');
        });

        Schema::create('tramite_recordatorios_enviados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->cascadeOnDelete();
            $table->string('periodo', 10)->nullable();
            $table->string('tipo', 64);
            $table->timestamp('enviado_en')->useCurrent();

            $table->unique(['tramite_id', 'periodo', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_recordatorios_enviados');

        Schema::table('tarea_documentos', function (Blueprint $table) {
            $table->dropIndex(['periodo']);
            $table->dropColumn('periodo');
        });

        Schema::table('tramites', function (Blueprint $table) {
            $table->dropIndex(['es_recurrente']);
            $table->dropIndex(['periodo_actual']);
            $table->dropColumn([
                'es_recurrente',
                'frecuencia',
                'dia_vencimiento_mes',
                'hora_vencimiento',
                'periodo_actual',
                'notificar_cada_periodo',
            ]);
        });
    }
};
