<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            $table->boolean('recurrencia_activa')->default(true)->after('notificar_cada_periodo');
            $table->timestamp('recurrencia_anulada_en')->nullable()->after('recurrencia_activa');
        });
    }

    public function down(): void
    {
        Schema::table('tramites', function (Blueprint $table) {
            $table->dropColumn(['recurrencia_activa', 'recurrencia_anulada_en']);
        });
    }
};
