<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('declaraciones_mensuales', function (Blueprint $table) {
            $table->string('modulo', 20)->default('afp')->after('mes');
            $table->decimal('monto_total_ganado', 14, 2)->nullable()->after('modulo');
            $table->decimal('monto_deposito_cns', 14, 2)->nullable()->after('monto_total_ganado');
            $table->decimal('monto_aportes_gestoras', 14, 2)->nullable()->after('monto_deposito_cns');
            $table->decimal('monto_aporte_solidario_gestora', 14, 2)->nullable()->after('monto_aportes_gestoras');
            $table->decimal('monto_planilla_mensual_mdt', 14, 2)->nullable()->after('monto_aporte_solidario_gestora');
            $table->decimal('monto_seprec_registro_poder_consultora', 14, 2)->nullable()->after('monto_planilla_mensual_mdt');

            $table->dropUnique(['empresa_cliente_id', 'anio', 'mes']);
            $table->unique(['empresa_cliente_id', 'anio', 'mes', 'modulo'], 'decl_mensual_empresa_periodo_modulo_unique');
            $table->index(['empresa_cliente_id', 'modulo', 'anio', 'mes'], 'decl_mensual_empresa_modulo_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('declaraciones_mensuales', function (Blueprint $table) {
            $table->dropUnique('decl_mensual_empresa_periodo_modulo_unique');
            $table->dropIndex('decl_mensual_empresa_modulo_periodo_idx');
            $table->unique(['empresa_cliente_id', 'anio', 'mes']);

            $table->dropColumn([
                'modulo',
                'monto_total_ganado',
                'monto_deposito_cns',
                'monto_aportes_gestoras',
                'monto_aporte_solidario_gestora',
                'monto_planilla_mensual_mdt',
                'monto_seprec_registro_poder_consultora',
            ]);
        });
    }
};
