<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUsuarioTipo
{
    /**
     * @param  string  ...$tipos  valores de usuarios.tipo permitidos
     */
    public function handle(Request $request, Closure $next, string ...$tipos): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->tipo, $tipos, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado para este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
