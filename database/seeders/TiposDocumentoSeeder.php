<?php

namespace Database\Seeders;

use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

class TiposDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['modulo' => 'afp', 'nombre' => 'Planilla de Aportes AFP', 'obligatorio' => true, 'es_periodico' => true, 'orden_visualizacion' => 1],
            ['modulo' => 'afp', 'nombre' => 'Comprobante de Pago AFP', 'obligatorio' => true, 'es_periodico' => true, 'orden_visualizacion' => 2],
            ['modulo' => 'afp', 'nombre' => 'Ficha de Afiliación AFP', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 3],
            ['modulo' => 'afp', 'nombre' => 'Certificado de Saldo AFP', 'obligatorio' => false, 'es_periodico' => false, 'orden_visualizacion' => 4],

            ['modulo' => 'caja', 'nombre' => 'Formulario de Afiliación CNS', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 1],
            ['modulo' => 'caja', 'nombre' => 'Póliza Mensual CAJA', 'obligatorio' => true, 'es_periodico' => true, 'orden_visualizacion' => 2],
            ['modulo' => 'caja', 'nombre' => 'Carnet de Asegurado', 'obligatorio' => false, 'es_periodico' => false, 'orden_visualizacion' => 3],

            ['modulo' => 'ministerio', 'nombre' => 'Contrato de Trabajo', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 1],
            ['modulo' => 'ministerio', 'nombre' => 'Planilla Laboral Mensual', 'obligatorio' => true, 'es_periodico' => true, 'orden_visualizacion' => 2],
            ['modulo' => 'ministerio', 'nombre' => 'Comprobante de Registro MT', 'obligatorio' => true, 'es_periodico' => false, 'orden_visualizacion' => 3],
        ];

        foreach ($rows as $r) {
            TipoDocumento::query()->updateOrCreate(
                ['modulo' => $r['modulo'], 'nombre' => $r['nombre']],
                [
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
