<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaConsultora;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LaboraAuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'string'],
            'correo' => ['nullable', 'string'],
            'nombre_usuario' => ['nullable', 'string', 'max:80'],
            'password' => ['required', 'string'],
        ]);

        $login = $data['email'] ?? $data['correo'] ?? $data['nombre_usuario'] ?? null;
        if (! $login) {
            return $this->fail('Debe enviar correo o nombre_usuario.', 422);
        }

        $user = Usuario::query()
            ->where('correo', $login)
            ->orWhere('nombre_usuario', $login)
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->contrasena_hash)) {
            return $this->fail('Credenciales incorrectas.', 401);
        }

        if ($user->bloqueado_hasta && $user->bloqueado_hasta->isFuture()) {
            return $this->fail('Usuario temporalmente bloqueado.', 423);
        }

        if ($user->estado === 'inactivo') {
            return $this->fail('Tu acceso está deshabilitado. Contacta al administrador o a tu consultora.', 403);
        }

        if ($user->estado === 'pendiente_activacion') {
            return $this->fail('Cuenta pendiente de activación. Usa el enlace del correo o pide que habiliten tu acceso desde administración.', 403);
        }

        $user->forceFill([
            'ultimo_acceso' => now(),
            'ip_ultimo_acceso' => $request->ip(),
            'intentos_fallidos' => 0,
        ])->save();

        $token = $user->createToken('spa')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->fresh()->toApiArray(),
        ], 'Inicio de sesión exitoso');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->ok(null, 'Sesión cerrada');
    }

    public function perfil(Request $request): JsonResponse
    {
        $u = $request->user();

        return $this->ok([
            'user' => $u->toApiArray(),
        ]);
    }

    /**
     * Activa cuenta consultora u otros usuarios con token del correo (Fase 1).
     */
    public function activar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        $hash = hash('sha256', $data['token']);

        $user = Usuario::query()
            ->where('token_activacion', $hash)
            ->where('estado', 'pendiente_activacion')
            ->first();

        if (! $user) {
            return $this->fail('Token inválido o ya utilizado.', 422);
        }

        if ($user->token_activacion_exp && $user->token_activacion_exp->isPast()) {
            return $this->fail('El enlace de activación expiró.', 422);
        }

        return DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'contrasena_hash' => Hash::make($data['password']),
                'estado' => 'activo',
                'verificado' => true,
                'debe_cambiar_contrasena' => false,
                'token_activacion' => null,
                'token_activacion_exp' => null,
            ])->save();

            if ($user->tipo === 'consultora') {
                $empresa = EmpresaConsultora::query()->where('usuario_id', $user->id)->first();
                if ($empresa) {
                    $empresa->update(['estado' => 'activo']);
                }
            }

            return $this->ok(['user' => $user->fresh()->toApiArray()], 'Cuenta activada');
        });
    }

    /**
     * Primer acceso con contraseña temporal (colaborador / empresa cliente).
     */
    public function primerAcceso(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre_usuario' => ['required', 'string'],
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        $user = Usuario::query()
            ->where('nombre_usuario', $data['nombre_usuario'])
            ->first();

        if (! $user || ! Hash::check($data['password_actual'], $user->contrasena_hash)) {
            return $this->fail('Credenciales incorrectas.', 401);
        }

        $user->forceFill([
            'contrasena_hash' => Hash::make($data['password']),
            'debe_cambiar_contrasena' => false,
        ])->save();

        return $this->ok(['user' => $user->fresh()->toApiArray()], 'Contraseña actualizada');
    }

    /**
     * Tras iniciar sesión: cambio obligatorio de contraseña (usuario autenticado).
     */
    public function cambiarContrasenaInicial(Request $request): JsonResponse
    {
        $u = $request->user();
        if (! $u->debe_cambiar_contrasena) {
            return $this->fail('No debes cambiar la contraseña en este momento.', 422);
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        $u->forceFill([
            'contrasena_hash' => Hash::make($data['password']),
            'debe_cambiar_contrasena' => false,
        ])->save();

        return $this->ok(['user' => $u->fresh()->toApiArray()], 'Contraseña actualizada');
    }
}
