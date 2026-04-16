<?php

namespace Database\Seeders;

use App\Models\InstitucionFinanciera;
use Illuminate\Database\Seeder;

/**
 * Bancos múltiples, PYME y cooperativas — referencia Bolivia.
 */
class InstitucionesFinancierasBoliviaSeeder extends Seeder
{
    public function run(): void
    {
        $orden = 0;
        $insert = function (string $nombre, string $tipo) use (&$orden) {
            InstitucionFinanciera::query()->create([
                'nombre' => $nombre,
                'tipo' => $tipo,
                'orden' => ++$orden,
                'activo' => true,
            ]);
        };

        foreach ([
            'Banco Unión',
            'Banco Mercantil Santa Cruz',
            'Banco Nacional de Bolivia (BNB)',
            'Banco BISA',
            'Banco de Crédito de Bolivia (BCP)',
            'Banco Económico',
            'Banco Ganadero',
            'BancoSol',
            'Banco FIE',
            'Banco Prodem',
            'Banco Fortaleza',
            'Banco de la Nación Argentina',
            'Banco de Desarrollo Productivo (BDP)',
        ] as $nombre) {
            $insert($nombre, 'banco');
        }

        foreach ([
            'Bancos PYME',
            'Banco PYME Ecofuturo',
            'Banco PYME de la Comunidad',
        ] as $nombre) {
            $insert($nombre, 'pyme');
        }

        foreach ([
            'Cooperativa Jesús Nazareno',
            'Cooperativa San Martín de Porres',
            'Cooperativa Loyola',
            'Cooperativa Fátima',
            'Cooperativa Progreso',
            'Cooperativa San Pedro',
            'Cooperativa Catedral',
            'Cooperativa Comarapa',
            'Cooperativa La Merced',
            'Cooperativa Magisterio Rural',
            'Cooperativa San Antonio',
            'Cooperativa San José de Punata',
            'Cooperativa Cristo Rey',
            'Cooperativa Paulo VI',
            'Cooperativa Hospicio',
        ] as $nombre) {
            $insert($nombre, 'cooperativa');
        }
    }
}
