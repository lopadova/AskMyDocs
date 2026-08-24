<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Services\Widget\WidgetSessionTokenService;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
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
        $identity = $this->identity($request, $key);

        // Se è specificato un session_id, risolvilo dentro l'intero confine
        // proprietario. Una sessione autenticata è visibile solo alla stessa
        // identità: conoscere il suo UUID non deve consentire a un caller pk_
        // anonimo (o a un'altra identità) di coniare un wt_ che la erediti.
        $session = null;
        $sessionId = $this->nullableString($data['session_id'] ?? null);
        if ($sessionId !== null) {
            $query = WidgetSession::query()
                ->forTenant($this->tenants->current())
                ->where('public_session_id', $sessionId)
                ->where('widget_key_id', $key->id)
                ->where('project_key', $key->project_key);

            $query->where(function ($query) use ($identity): void {
                $query->whereNull('widget_identity_id');
                if ($identity !== null) {
                    $query->orWhere('widget_identity_id', $identity->id);
                }
            });

            $session = $query->first();
            if ($session === null) {
                return response()->json([
                    'error' => 'widget_session_not_found',
                    'message' => 'Widget session not found.',
                ], 404);
            }
        }

        try {
            $result = $tokenService->mint($key, $session, $origin, $identity);
        } catch (AuthorizationException) {
            // The service repeats the ownership check under lock. If ownership
            // changes between the controller lookup and that lock, preserve the
            // same IDOR-safe response instead of leaking a distinct 403.
            return response()->json([
                'error' => 'widget_session_not_found',
                'message' => 'Widget session not found.',
            ], 404);
        }

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

    private function identity(Request $request, WidgetKey $key): ?WidgetIdentity
    {
        $identity = $request->attributes->get(ResolveWidgetKey::ATTR_IDENTITY);
        if (! $identity instanceof WidgetIdentity) {
            return null;
        }

        // Defense in depth: l'identità proviene da un wu_ già validato dal
        // middleware, ma l'endpoint ribadisce tenant/key/project prima di
        // usarla in una decisione di autorizzazione.
        if ($identity->tenant_id !== $this->tenants->current()
            || $identity->tenant_id !== $key->tenant_id
            || $identity->widget_key_id !== $key->id
            || $identity->project_key !== $key->project_key) {
            return null;
        }

        return $identity;
    }
}
