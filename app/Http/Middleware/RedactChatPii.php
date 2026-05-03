<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Padosoft\PiiRedactor\RedactorEngine;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redact PII from the `content` field of incoming chat-message requests.
 *
 * Wraps the two POST endpoints that persist user-submitted chat content:
 *
 *   POST /conversations/{conversation}/messages         (sync — MessageController::store)
 *   POST /conversations/{conversation}/messages/stream  (SSE — MessageStreamController::store)
 *
 * When BOTH `kb.pii_redactor.enabled` AND
 * `kb.pii_redactor.persist_chat_redacted` are true, the middleware reads
 * the request's `content` input, hands it to the package's RedactorEngine
 * (configured strategy is taken from `pii-redactor.strategy` —
 * `tokenise` is the recommended choice for chat persistence so operators
 * with the `pii.detokenize` permission can recover originals), and
 * replaces the request body's `content` with the redacted value before
 * the controller runs.
 *
 * Default posture: pass-through. v3 hosts upgrading to v4.1 see zero
 * behaviour change until they explicitly flip BOTH integration knobs ON.
 *
 * Scope (architecture-tested by `tests/Architecture/PiiRedactionMiddlewareScopeTest`):
 *   - Bound ONLY to the two chat-message routes via the `redact-chat-pii`
 *     alias declared in `bootstrap/app.php`.
 *   - NEVER bound to `/admin/*` / `/insights/*` / `/api/kb/ingest|delete/*`
 *     routes — those carry curator-supplied content that must NOT be
 *     redacted (otherwise canonical promotion + ingestion break).
 *
 * Per-tenant namespace (deferred): v1.1 of `padosoft/laravel-pii-redactor`
 * does not yet expose a per-tenant `tokenise` namespace knob (the strategy
 * is salt + idHexLength only). A future v1.2+ release of the package will
 * add a `withNamespace($tenantId)` builder that this middleware will
 * upgrade to use, isolating token spaces across tenants. For v4.1, the
 * shared salt + tenant-scoped database token store (W4.1.C migration) is
 * already sufficient: cross-tenant detokenisation is structurally
 * impossible because the `pii_token_maps` row carries the tenant_id and
 * the `LogViewerController::detokenize` action filters by the active
 * tenant.
 */
final class RedactChatPii
{
    public function __construct(
        private readonly RedactorEngine $engine,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = config('kb.pii_redactor');

        if (! ($config['enabled'] ?? false) || ! ($config['persist_chat_redacted'] ?? false)) {
            return $next($request);
        }

        $content = $request->input('content');
        if (! is_string($content) || $content === '') {
            return $next($request);
        }

        $redacted = $this->engine->redact($content);
        $request->merge(['content' => $redacted]);

        return $next($request);
    }
}
