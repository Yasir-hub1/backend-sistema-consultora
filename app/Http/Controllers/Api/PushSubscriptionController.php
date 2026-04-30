<?php

namespace App\Http\Controllers\Api;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends ApiController
{
    public function publicKey(): JsonResponse
    {
        $publicKey = (string) config('services.webpush.public_key', env('WEB_PUSH_PUBLIC_KEY', ''));
        if ($publicKey === '') {
            return $this->fail('Clave pública de Web Push no configurada.', 503);
        }

        return $this->ok(['public_key' => $publicKey]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ]);

        PushSubscription::query()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'usuario_id' => $request->user()->id,
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'last_seen_at' => now(),
            ]
        );

        return $this->ok(null, 'Suscripción push registrada.');
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::query()
            ->where('usuario_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return $this->ok(null, 'Suscripción push eliminada.');
    }
}
