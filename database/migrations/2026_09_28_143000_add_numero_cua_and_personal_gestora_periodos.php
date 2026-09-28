<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            $table->string('numero_cua', 20)->nullable()->after('ci');
            $table->unique(['empresa_id', 'numero_cua']);
        });

        Schema::create('personal_gestora_periodos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personal_id')->constrained('personal')->cascadeOnDelete();
            $table->unsignedSmallInteger('anio');
            $table->unsignedTinyInteger('mes');
            $table->unsignedTinyInteger('dias_trabajados')->default(30);
            $table->decimal('total_ganado', 12, 2)->default(0);
            $table->boolean('habilitado')->default(true);
            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['personal_id', 'anio', 'mes']);
            $table->index(['anio', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_gestora_periodos');

        Schema::table('personal', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'numero_cua']);
            $table->dropColumn('numero_cua');
        });
    }
};
