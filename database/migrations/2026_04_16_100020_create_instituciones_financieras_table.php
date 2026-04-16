<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instituciones_financieras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('tipo', 32);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamp('creado_en')->useCurrent();

            $table->index(['tipo', 'activo']);
            $table->index('orden');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instituciones_financieras');
    }
};
