<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\Personal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalController extends ApiController
{
    private function empresa(Request $request)
    {
        return $request->user()->empresaClienteComoUsuario;
    }

    public function index(Request $request): JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $q = $emp->personal();

        if ($s = trim((string) $request->get('search'))) {
            if ($s !== '') {
                $like = '%'.$s.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('nombres', 'like', $like)
                        ->orWhere('apellidos', 'like', $like)
                        ->orWhere('ci', 'like', $like)
                        ->orWhere('cargo', 'like', $like);
                });
            }
        }

        $p = $q->orderBy('apellidos')->paginate(min((int) $request->get('per_page', 15), 100));

        $items = collect($p->items())->map(function (Personal $per) {
            return [
                'id' => $per->id,
                'nombres' => $per->nombres,
                'apellidos' => $per->apellidos,
                'ci' => $per->ci,
                'cargo' => $per->cargo,
            ];
        })->all();

        return $this->ok([
            'empresa' => $emp->only(['id', 'nombre', 'nit', 'razon_social']),
            'data' => $items,
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
            'stats' => [
                'total_personal' => $emp->personal()->count(),
            ],
        ]);
    }
}
