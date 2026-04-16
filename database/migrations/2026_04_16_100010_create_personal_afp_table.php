<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_afp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personal_id')->unique()->constrained('personal')->cascadeOnDelete();
            $table->string('afp_nombre', 100)->nullable();
            $table->string('numero_afiliado', 50)->nullable();
            $table->date('fecha_afiliacion')->nullable();
            $table->string('estado', 32)->default('sin_datos');
            $table->text('observaciones')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('actualizado_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_afp');
    }
};
