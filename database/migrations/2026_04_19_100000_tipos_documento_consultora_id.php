<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->dropUnique(['modulo', 'nombre']);
        });

        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->foreignId('consultora_id')->nullable()->after('id')->constrained('empresas_consultoras')->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX tipos_documento_modulo_nombre_sistema ON tipos_documento (modulo, nombre) WHERE consultora_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX tipos_documento_consultora_modulo_nombre ON tipos_documento (consultora_id, modulo, nombre) WHERE consultora_id IS NOT NULL');
        } else {
            Schema::table('tipos_documento', function (Blueprint $table) {
                $table->unique(['consultora_id', 'modulo', 'nombre'], 'tipos_documento_consultora_modulo_nombre_unique');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS tipos_documento_modulo_nombre_sistema');
            DB::statement('DROP INDEX IF EXISTS tipos_documento_consultora_modulo_nombre');
        } else {
            Schema::table('tipos_documento', function (Blueprint $table) {
                $table->dropUnique('tipos_documento_consultora_modulo_nombre_unique');
            });
        }

        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->dropForeign(['consultora_id']);
            $table->dropColumn('consultora_id');
        });

        Schema::table('tipos_documento', function (Blueprint $table) {
            $table->unique(['modulo', 'nombre']);
        });
    }
};
