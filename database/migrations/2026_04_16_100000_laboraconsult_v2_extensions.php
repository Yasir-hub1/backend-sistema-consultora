<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensiones opcionales del script v2 (solo PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        // No se eliminan extensiones compartidas.
    }
};
