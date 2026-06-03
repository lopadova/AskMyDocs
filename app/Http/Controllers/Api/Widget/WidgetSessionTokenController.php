<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Services\Widget\WidgetSessionTokenService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * M5.2 — Endpoint per coniare un token di sessione opzionale origin-bound.
 *
 * Elimina la necessità di passare la public_key (pk_) a ogni richiesta
 * dal browser: il FE chiama questo endpoint una volta, ottiene un token
 * a breve scadenza, e lo usa in `Authorization: Bearer <wt_…>` nelle
 * richieste successive. Il token è consumato atomicamente (R21).
 *
 * Gira dietro `widget.key` → key + tenant già risolti dal middleware.
 */
final class WidgetSessionTokenController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    public function mint(Request $request, WidgetSessionTokenService $tokenService): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
        ]);

        $key = $this->key($request);
        $origin = $this->nullableString($request->header('Origin'));

        // Se è specificato un session_id, risolvilo scoping sulla key
        $session = null;
        $sessionId = $this->nullableString($data['session_id'] ?? null);
        if ($sessionId !== null) {
            $session = WidgetSession::query()
                ->forTenant($this->tenants->current())
                ->where('public_session_id', $sessionId)
                ->where('widget_key_id', $key->id)
                ->first();
        }

        $result = $tokenService->mint($key, $session, $origin);

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at'],
        ], 201);
    }

    private function key(Request $request): WidgetKey
    {
        /** @var WidgetKey $key */
        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);

        return $key;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}