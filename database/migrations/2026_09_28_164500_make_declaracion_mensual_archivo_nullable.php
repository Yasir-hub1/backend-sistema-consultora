<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN nombre_archivo DROP NOT NULL');
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN nombre_original DROP NOT NULL');
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN ruta_archivo DROP NOT NULL');
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN formato DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE declaraciones_mensuales SET nombre_archivo = 'sin-archivo' WHERE nombre_archivo IS NULL");
        DB::statement("UPDATE declaraciones_mensuales SET nombre_original = 'sin-archivo' WHERE nombre_original IS NULL");
        DB::statement("UPDATE declaraciones_mensuales SET ruta_archivo = 'sin-archivo' WHERE ruta_archivo IS NULL");
        DB::statement("UPDATE declaraciones_mensuales SET formato = 'pdf' WHERE formato IS NULL");

        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN nombre_archivo SET NOT NULL');
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN nombre_original SET NOT NULL');
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN ruta_archivo SET NOT NULL');
        DB::statement('ALTER TABLE declaraciones_mensuales ALTER COLUMN formato SET NOT NULL');
    }
};
