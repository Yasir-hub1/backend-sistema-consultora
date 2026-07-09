<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Recurrentes usan Y-m (7); no recurrentes usan fecha completa Y-m-d (10).
        DB::statement('ALTER TABLE tramite_recordatorios_enviados ALTER COLUMN periodo TYPE VARCHAR(10)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tramite_recordatorios_enviados ALTER COLUMN periodo TYPE VARCHAR(7)');
    }
};
