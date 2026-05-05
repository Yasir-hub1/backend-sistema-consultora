<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureUsuarioTipo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Autenticación vía Bearer (createToken). Sin middleware "stateful" de Sanctum:
        // si lo activas, el navegador debe llamar antes a GET /sanctum/csrf-cookie y enviar X-XSRF-TOKEN.
        // Para SPA con token en localStorage, no hace falta EnsureFrontendRequestsAreStateful.

        // multipart/form-data suele enviar '' en campos vacíos; sin esto fallan reglas nullable|email|numeric.
        $middleware->api(prepend: [
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);

        $middleware->alias([
            'verified' => EnsureEmailIsVerified::class,
            'usuario.tipo' => EnsureUsuarioTipo::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
