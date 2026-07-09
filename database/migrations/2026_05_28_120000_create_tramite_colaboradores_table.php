<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tramite_id')->constrained('tramites')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->timestamp('asignado_en')->useCurrent();

            $table->unique(['tramite_id', 'colaborador_id']);
            $table->index('colaborador_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_colaboradores');
    }
};
