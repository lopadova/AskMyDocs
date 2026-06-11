<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WidgetKey;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveWidgetKey — gate del canale pubblico del widget (`widget.key`).
 *
 * Risolve la `WidgetKey` dall'header `X-Widget-Key` (sempre = public_key,
 * identifica la riga) e decide la modalità d'accesso (D1 = A + B):
 *
 *   - **A — browser**: solo public_key. RICHIEDE che l'`Origin` sia nella
 *     allowlist della key (confronto esatto, R19). Caso "embed" su sito terzo.
 *   - **B — proxy server-to-server**: public_key + `Authorization: Bearer
 *     <secret>` che combacia con `secret_hash`. NESSUN controllo Origin
 *     (alta fiducia, niente browser). Caso "il backend ospite fa da proxy".
 *
 * Imposta SEMPRE TenantContext + project DALLA KEY (R30): il client non può
 * indicare un tenant/progetto diverso. Espone la key/mode/project risolti
 * sugli attributi della request per i controller a valle.
 *
 * Fallimenti espliciti (R14): 401 key assente/sconosciuta, 403 inattiva /
 * Origin non in allowlist, 429 rate-limit. Mai 200 muto.
 */
final class ResolveWidgetKey
{
    public const MODE_BROWSER = 'browser';
    public const MODE_PROXY = 'proxy';

    public const ATTR_KEY = 'widget_key';
    public const ATTR_MODE = 'widget_mode';
    public const ATTR_PROJECT = 'widget_project';

    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        // #10 — il branch session-token (wt_) va PRIMA del requisito X-Widget-Key:
        // in token mode il client invia SOLO `Authorization: Bearer wt_…` (niente
        // X-Widget-Key, vedi transport.ts). Il token risolve key+tenant da sé;
        // richiedere prima X-Widget-Key rendeva la modalità M5.2 morta (401).
        $bearer = (string) ($request->bearerToken() ?? '');
        if ($bearer !== '' && str_starts_with($bearer, 'wt_')) {
            return $this->resolveFromSessionToken($request, $next, $bearer);
        }

        $publicKey = (string) $request->header('X-Widget-Key', '');
        if ($publicKey === '') {
            return $this->deny(401, 'widget_key_missing', 'Missing X-Widget-Key header.');
        }

        $key = WidgetKey::query()->where('public_key', $publicKey)->first();
        if ($key === null) {
            return $this->deny(401, 'widget_key_invalid', 'Unknown widget key.');
        }

        if (! $key->is_active) {
            return $this->deny(403, 'widget_key_inactive', 'This widget key is disabled.');
        }

        // Modalità: proxy (B) se arriva un Bearer che combacia col secret;
        // altrimenti browser (A) con controllo Origin.
        $mode = ($bearer !== '' && $key->matchesSecret($bearer))
            ? self::MODE_PROXY
            : self::MODE_BROWSER;

        if ($mode === self::MODE_BROWSER && ! $key->originAllowed($request->header('Origin'))) {
            return $this->deny(403, 'origin_not_allowed', 'This origin is not allowed for this widget key.');
        }

        if ($rl = $this->rateLimited($request, $key)) {
            return $rl;
        }

        // R30 — tenant e project SEMPRE dalla key, mai dal client.
        $this->tenants->set($key->tenant_id);
        $request->attributes->set(self::ATTR_KEY, $key);
        $request->attributes->set(self::ATTR_MODE, $mode);
        $request->attributes->set(self::ATTR_PROJECT, $key->project_key);

        $this->touchLastUsed($key);

        return $next($request);
    }

    /**
     * Rate-limit per chiave+IP (bucket orario).
     * Ritorna la response 429 con header Retry-After, oppure null se il
     * limite non è stato raggiunto.
     */
    private function rateLimited(Request $request, WidgetKey $key): ?Response
    {
        $limit = max(1, (int) $key->rate_limit);
        $bucket = 'widget:'.$key->public_key.':'.$request->ip();

        if (RateLimiter::tooManyAttempts($bucket, $limit)) {
            $retryAfter = RateLimiter::availableIn($bucket);

            return $this->deny(429, 'rate_limited', 'Too many widget requests. Slow down.', $retryAfter);
        }

        RateLimiter::hit($bucket, 60);

        return null;
    }

    /**
     * Rate-limit per sessione (M5.4).
     * Bucket separato dal per-key-per-IP: limita il traffico su una singola
     * sessione. Ritorna la response 429 con Retry-Attest, oppure null.
     */
    public static function sessionRateLimited(string $publicSessionId, int $perMinuteLimit): ?Response
    {
        $bucket = 'widget:session:'.$publicSessionId;

        if (RateLimiter::tooManyAttempts($bucket, $perMinuteLimit)) {
            $retryAfter = RateLimiter::availableIn($bucket);

            return response()->json([
                'error' => 'session_rate_limited',
                'message' => 'Too many requests for this session. Slow down.',
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($bucket, 60);

        return null;
    }

    /**
     * #26 — Aggiorna last_used_at con throttle: una UPDATE per OGNI richiesta
     * (incluse /setup e ogni /step) contendeva sulla singola riga hot della key.
     * Scriviamo solo se mai usata o più vecchia di 60s.
     */
    private function touchLastUsed(WidgetKey $key): void
    {
        $last = $key->last_used_at;
        if ($last !== null && $last->gt(now()->subSeconds(60))) {
            return;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    private function deny(int $status, string $error, string $message, int $retryAfter = 0): Response
    {
        $response = response()->json([
            'error' => $error,
            'message' => $message,
        ], $status);

        if ($status === 429 && $retryAfter > 0) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /**
     * M5.2 — Risolve la key da un session token (wt_…).
     *
     * Il consumo è atomico (R21): la transazione con lockForUpdate in
     * WidgetSessionTokenService::consume() garantisce che lo stesso token
     * non venga consumato due volte. Se il token è valido, imposta la key
     * e il contesto tenant; altrimenti 401.
     */
    private function resolveFromSessionToken(Request $request, Closure $next, string $bearer): Response
    {
        $tokenService = app(\App\Services\Widget\WidgetSessionTokenService::class);
        $origin = $request->header('Origin');

        // #12 — rate-limit PRIMA del consumo single-use: una lettura read-only del
        // token dà la key per il bucket, così un 429 NON brucia il token (il retry
        // conforme dell'utente può riuscire). Specchio del path pk-mode, che
        // controlla il rate-limit prima di qualsiasi mutazione di stato.
        $key = $tokenService->peekKey($bearer);
        if ($key === null || ! $key->is_active) {
            return $this->deny(401, 'session_token_invalid', 'Session token is invalid, expired, or already used.');
        }
        if ($rl = $this->rateLimited($request, $key)) {
            return $rl;
        }

        // Consumo atomico (R21) DOPO il rate-limit. consume() ri-valida tutto
        // (scadenza/consumo/origin/key attiva) sotto lock.
        $result = $tokenService->consume($bearer, $origin);
        if ($result === null) {
            return $this->deny(401, 'session_token_invalid', 'Session token is invalid, expired, or already used.');
        }

        $key = $result['key'];

        // R30 — tenant e project SEMPRE dalla key
        $this->tenants->set($key->tenant_id);
        $request->attributes->set(self::ATTR_KEY, $key);
        $request->attributes->set(self::ATTR_MODE, self::MODE_BROWSER);
        $request->attributes->set(self::ATTR_PROJECT, $key->project_key);

        // Imposta anche la sessione risolta dal token, se presente
        if ($result['session'] !== null) {
            $request->attributes->set('widget_session', $result['session']);
        }

        $this->touchLastUsed($key);

        return $next($request);
    }
}
