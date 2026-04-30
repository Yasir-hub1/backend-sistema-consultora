<?php

namespace Database\Seeders;

use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class TiposDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['modulo' => 'afp', 'caja_variante' => null, 'nombre' => 'Planilla de Aportes AFP', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 1],
            ['modulo' => 'afp', 'caja_variante' => null, 'nombre' => 'Comprobante de Pago AFP', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 2],
            ['modulo' => 'afp', 'caja_variante' => null, 'nombre' => 'Ficha de Afiliación AFP', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 3],
            ['modulo' => 'afp', 'caja_variante' => null, 'nombre' => 'Certificado de Saldo AFP', 'obligatorio' => false, 'es_periodico' => false, 'orden_visualizacion' => 4],

            // CAJA — Nacional (un archivo por trabajador en legajo)
            ['modulo' => 'caja', 'caja_variante' => 'nacional', 'nombre' => 'Formulario 04-03 CNS', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 1],

            // CAJA — Petrolera (cuatro trámites, uno por tipo y trabajador)
            ['modulo' => 'caja', 'caja_variante' => 'petrolera', 'nombre' => 'Petrolera — Programación', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 1],
            ['modulo' => 'caja', 'caja_variante' => 'petrolera', 'nombre' => 'Petrolera — Presentación de requisitos', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 2],
            ['modulo' => 'caja', 'caja_variante' => 'petrolera', 'nombre' => 'Petrolera — Aviso de afiliación', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 3],
            ['modulo' => 'caja', 'caja_variante' => 'petrolera', 'nombre' => 'Petrolera — Formalización', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 4],

            ['modulo' => 'ministerio', 'caja_variante' => null, 'nombre' => 'Contrato de Trabajo', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 1],
            ['modulo' => 'ministerio', 'caja_variante' => null, 'nombre' => 'Planilla Laboral Mensual', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 2],
            ['modulo' => 'ministerio', 'caja_variante' => null, 'nombre' => 'Comprobante de Registro MT', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 3],
        ];

        // Catálogo CAJA anterior (sin variante): desactivar para no mezclar reglas de cumplimiento
        foreach ([
            'Formulario de Afiliación CNS',
            'Póliza Mensual CAJA',
            'Carnet de Asegurado',
        ] as $legacyNombre) {
            TipoDocumento::query()
                ->where('modulo', 'caja')
                ->where('nombre', $legacyNombre)
                ->update(['activo' => false]);
        }

        foreach ($rows as $r) {
            TipoDocumento::query()->updateOrCreate(
                [
                    'modulo' => $r['modulo'],
                    'nombre' => $r['nombre'],
                    'consultora_id' => null,
                ],
                [
                    'caja_variante' => $r['caja_variante'],
                    'descripcion' => null,
                    'obligatorio' => $r['obligatorio'],
                    'es_periodico' => $r['es_periodico'],
                    'formatos_permitidos' => 'pdf,xlsx,docx,jpg,png',
                    'tamano_maximo_mb' => 10,
                    'activo' => true,
                    'orden_visualizacion' => $r['orden_visualizacion'],
                ]
            );
        }
    }
}
