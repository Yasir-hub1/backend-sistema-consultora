<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\PushSubscription;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushNotificationService
{
    public function sendForAlerta(Alerta $alerta): void
    {
        $publicKey = (string) config('services.webpush.public_key', env('WEB_PUSH_PUBLIC_KEY', ''));
        $privateKey = (string) config('services.webpush.private_key', env('WEB_PUSH_PRIVATE_KEY', ''));
        $subject = (string) config('services.webpush.subject', env('WEB_PUSH_SUBJECT', 'mailto:soporte@localhost'));

        if ($publicKey === '' || $privateKey === '') {
            return;
        }

        $usuarioIds = $this->resolveDestinatarios($alerta);
        if ($usuarioIds->isEmpty()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('usuario_id', $usuarioIds->all())
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $usuarios = Usuario::query()
            ->whereIn('id', $subscriptions->pluck('usuario_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($subscriptions as $sub) {
            $usuario = $usuarios->get($sub->usuario_id);
            $path = $this->resolvePushPath($alerta, $usuario);

            $payload = json_encode([
                'type' => 'alerta',
                'alerta_id' => $alerta->id,
                'titulo' => $alerta->titulo,
                'descripcion' => $alerta->descripcion,
                'nivel' => $alerta->nivel,
                'modulo' => $alerta->modulo,
                'empresa_id' => $alerta->empresa_id,
                'path' => $path,
                'created_at' => optional($alerta->creado_en)->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE);

            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth,
                    'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                PushSubscription::query()
                    ->where('endpoint', (string) $report->getEndpoint())
                    ->delete();
            }
        }
    }

    private function resolveDestinatarios(Alerta $alerta): Collection
    {
        $alerta->loadMissing([
            'consultora:id,usuario_id',
            'colaboradorAsignado:id,usuario_id',
            'empresaCliente:id,usuario_id',
        ]);

        $ids = match ($alerta->modulo) {
            'asignacion_empresa' => collect([
                $alerta->colaboradorAsignado?->usuario_id,
            ]),
            // Portal empresa-cliente: avisos por declaraciones cargadas por la consultora/colaborador.
            'declaracion_mensual' => collect([
                $alerta->empresaCliente?->usuario_id,
            ]),
            'declaracion_aguinaldo' => collect([
                $alerta->empresaCliente?->usuario_id,
            ]),
            'registro_personal', 'acceso_portal' => collect([
                $alerta->consultora?->usuario_id,
            ]),
            default => collect([
                $alerta->consultora?->usuario_id,
                $alerta->colaboradorAsignado?->usuario_id,
            ]),
        };

        return $ids->filter()->unique()->values();
    }

    private function resolvePushPath(Alerta $alerta, ?Usuario $usuario): string
    {
        if (! $usuario) {
            return '/';
        }

        $paths = $alerta->contexto['paths'] ?? [];
        $key = match ($usuario->tipo) {
            'consultora' => 'consultora',
            'colaborador' => 'colaborador',
            'empresa_cliente' => 'empresa_cliente',
            default => null,
        };

        if ($key !== null && isset($paths[$key]) && is_string($paths[$key]) && $paths[$key] !== '') {
            return $this->normalizePath($paths[$key]);
        }

        return $this->fallbackPushPath($alerta, $usuario);
    }

    private function normalizePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private function fallbackPushPath(Alerta $alerta, Usuario $usuario): string
    {
        $mod = $alerta->modulo;
        $eid = $alerta->empresa_id;
        $pid = $alerta->personal_id;
        $tipo = $usuario->tipo;

        if ($mod === 'asignacion_empresa' && $tipo === 'colaborador') {
            return $eid ? "/colaborador/empresas/{$eid}/personal" : '/colaborador/dashboard';
        }
        if ($mod === 'acceso_portal') {
            if ($tipo === 'consultora') {
                return $eid ? "/consultora/mis-empresas/{$eid}" : '/consultora/dashboard';
            }
            if ($tipo === 'empresa_cliente') {
                return '/empresa-cliente/dashboard';
            }
        }
        if ($mod === 'registro_personal') {
            if ($tipo === 'consultora') {
                return $eid ? "/consultora/mis-empresas/{$eid}" : '/consultora/dashboard';
            }
            if ($tipo === 'empresa_cliente') {
                return $pid ? "/empresa-cliente/personal/{$pid}" : '/empresa-cliente/personal';
            }
        }
        if ($mod === 'declaracion_mensual' && $tipo === 'empresa_cliente') {
            return '/empresa-cliente/declaraciones-mensuales';
        }

        return match ($tipo) {
            'consultora' => '/consultora/dashboard',
            'colaborador' => '/colaborador/dashboard',
            'empresa_cliente' => '/empresa-cliente/dashboard',
            default => '/',
        };
    }
}
