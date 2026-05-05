<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table) {
            $table->string('certificado_nacimiento_archivo_path')->nullable()->after('croquis_archivo_nombre');
            $table->string('certificado_nacimiento_archivo_nombre', 255)->nullable()->after('certificado_nacimiento_archivo_path');
        });
    }

    public function down(): void
    {
        Schema::table('personal', function (Blueprint $table) {
            $table->dropColumn([
                'certificado_nacimiento_archivo_path',
                'certificado_nacimiento_archivo_nombre',
            ]);
        });
    }
};
