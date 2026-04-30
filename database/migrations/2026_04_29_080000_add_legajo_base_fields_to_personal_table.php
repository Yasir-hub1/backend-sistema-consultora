<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table) {
            $table->string('curriculum_archivo_path')->nullable()->after('observaciones');
            $table->string('curriculum_archivo_nombre', 255)->nullable()->after('curriculum_archivo_path');
            $table->string('licencia_conducir_archivo_path')->nullable()->after('curriculum_archivo_nombre');
            $table->string('licencia_conducir_archivo_nombre', 255)->nullable()->after('licencia_conducir_archivo_path');
            $table->string('aviso_luz_agua_archivo_path')->nullable()->after('licencia_conducir_archivo_nombre');
            $table->string('aviso_luz_agua_archivo_nombre', 255)->nullable()->after('aviso_luz_agua_archivo_path');
            $table->string('croquis_archivo_path')->nullable()->after('aviso_luz_agua_archivo_nombre');
            $table->string('croquis_archivo_nombre', 255)->nullable()->after('croquis_archivo_path');
            $table->json('contactos_referencia')->nullable()->after('croquis_archivo_nombre');
            $table->string('correo_electronico', 150)->nullable()->after('contactos_referencia');
            $table->string('cuenta_bancaria', 120)->nullable()->after('correo_electronico');
        });
    }

    public function down(): void
    {
        Schema::table('personal', function (Blueprint $table) {
            $table->dropColumn([
                'curriculum_archivo_path',
                'curriculum_archivo_nombre',
                'licencia_conducir_archivo_path',
                'licencia_conducir_archivo_nombre',
                'aviso_luz_agua_archivo_path',
                'aviso_luz_agua_archivo_nombre',
                'croquis_archivo_path',
                'croquis_archivo_nombre',
                'contactos_referencia',
                'correo_electronico',
                'cuenta_bancaria',
            ]);
        });
    }
};
