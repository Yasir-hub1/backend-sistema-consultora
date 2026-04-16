<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios')->cascadeOnDelete();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('telefono', 20)->nullable();
            $table->timestamp('creado_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administradores');
    }
};
