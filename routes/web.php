<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\Api\AgentMessageController;
use App\Http\Controllers\Api\ChatExtrasController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageStreamController;
use App\Http\Controllers\Api\AgentRunEventController;
use App\Http\Controllers\Api\AgentRunControlController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (guest only)
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    // The auth UI (login / forgot-password / reset-password) IS the React SPA.
    // These GET routes render the SPA shell (`view('app')` via SpaController)
    // so React's own /login, /forgot-password and /reset-password routes own
    // the screen on a HARD page load too — not only on in-app (soft)
    // navigation. They previously served standalone Blade views with no @vite
    // bundle, so a cache-cleared reload of /login showed a second, un-branded
    // login page (the legacy pre-SPA UI) while an in-SPA navigation showed the
    // React one. Serving the shell here collapses both paths onto the single
    // React auth surface.
    //
    // Authentication itself runs over the stateful JSON `/api/auth/*` endpoints
    // (Sanctum; see routes/api.php). `POST /login` stays as a session-login
    // fallback and is exercised directly by LoginRedirectTest.
    //
    // `/reset-password` carries `?token=&email=` as QUERY params (matching the
    // SPA resetRoute search schema). Dropping the old `/reset-password/{token}`
    // path segment makes the framework's ResetPassword notification emit
    // exactly that query-string URL (non-path route params become query args).
    Route::get('/login', SpaController::class)->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    // Invite-only sign-up screen — also React (same SPA shell). The actual
    // account creation + invite redemption runs over POST /api/auth/register
    // (routes/api.php); this GET just hands the page to React.
    Route::get('/register', SpaController::class)->name('register');

    Route::get('/forgot-password', SpaController::class)->name('password.request');
    Route::get('/reset-password', SpaController::class)->name('password.reset');
});

/*
|--------------------------------------------------------------------------
| Protected Routes (auth required)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Canonical chat URL is the React SPA at /app/chat. The legacy Blade UI
    // lives at /chat-legacy until PR11 (Phase J) cleanup; /chat redirects to
    // the SPA so existing links keep working.
    Route::get('/', fn () => redirect('/app/chat'))->name('home');
    Route::get('/chat/{conversation?}', function ($conversation = null) {
        return redirect('/app/chat'.($conversation ? '/'.$conversation : ''));
    })->name('chat');
    Route::get('/chat-legacy/{conversation?}', [ChatController::class, 'index'])->name('chat.legacy');

    // v8.0.2 — AI Act gates (R-deep-review B): the same stack
    // applied to `POST /api/kb/chat` (routes/api.php) must hold on
    // the SPA's real chat endpoints (`POST /conversations/{id}/messages`
    // + the SSE stream variant). Without this the AI Act Art. 50
    // disclosure header is absent on the actual UX path and the
    // optional consent gate is bypassed. The `redact-chat-pii`
    // middleware stays first because it operates on the inbound
    // body BEFORE controllers, while disclosure/consent operate on
    // the response/authorization layer.
    //
    // Resolution is dynamic (config-driven) so the two route files
    // stay in lockstep: any future addition to the chat middleware
    // stack lands here once.
    $aiActConsentFeature = (string) config('ai-act-compliance.consent.gate_chat_feature', '');
    $chatPostMiddleware = ['redact-chat-pii', 'ai.disclosure'];
    if ($aiActConsentFeature !== '') {
        $chatPostMiddleware[] = 'ai.consent:' . $aiActConsentFeature;
    }

    // Conversation AJAX endpoints (session auth, no Sanctum needed).
    //
    // SECURITY (R30 / v8.0.3 C1): `tenant.authorize` MUST gate this group.
    // `ResolveTenant` honours an inbound `X-Tenant-Id` header unconditionally
    // (it runs before the user is resolved), and the SPA chat path
    // (`POST /conversations/{id}/messages` + the SSE variant below) drives
    // tenant-scoped RAG retrieval off `TenantContext->current()`. Without
    // `AuthorizeTenantHeader` here, any authenticated user could send
    // `X-Tenant-Id: victim` and receive answers + citations grounded in
    // another tenant's knowledge base. This mirrors the `/api/*` groups in
    // routes/api.php, which already carry the guard; the conversation
    // surface (the SPA's real chat path) was the gap.
    Route::prefix('conversations')->middleware('tenant.authorize')->group(function () use ($chatPostMiddleware) {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/', [ConversationController::class, 'store']);
        Route::patch('/{conversation}', [ConversationController::class, 'update']);
        Route::delete('/{conversation}', [ConversationController::class, 'destroy']);
        Route::get('/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/{conversation}/messages', [MessageController::class, 'store'])
            ->middleware($chatPostMiddleware);
        Route::post('/{conversation}/messages/agent', [AgentMessageController::class, 'store'])
            ->middleware($chatPostMiddleware);
        Route::post('/{conversation}/generate-title', [ConversationController::class, 'generateTitle']);
        Route::post('/{conversation}/messages/{message}/feedback', [FeedbackController::class, 'store']);

        // v4.5/W7 — Vercel AI SDK UI Tier 1 + Tier 2 surfaces.
        Route::post('/{conversation}/branch-from-message/{message}', [ChatExtrasController::class, 'branchFromMessage']);
        Route::post('/{conversation}/suggested-followups', [ChatExtrasController::class, 'suggestedFollowups']);
        // Truncate a conversation from a given message onwards (inclusive).
        // Called by the inline user-message edit flow before sendMessage()
        // so the BE history window re-runs from the edit point.
        Route::delete('/{conversation}/messages-from/{message}', [ChatExtrasController::class, 'truncateMessagesFrom']);
    });

    // v4.5/W7 — session-authenticated cost-rate lookup table for the
    // token/cost meter. Registered under the auth middleware (rates are
    // not secrets but the endpoint is part of the authenticated chat
    // surface). Response carries a 1-hour max-age so clients cache it.
    Route::get('/api/chat/cost-rates', [ChatExtrasController::class, 'costRates']);
});

// v4.0/W3.1 — SSE streaming variant of POST /messages, registered
// OUTSIDE the `auth` middleware group so we can apply our SSE-aware
// auth variant. Same conversation/auth/validation/filter contract as
// the synchronous route, but emits AI SDK v6 `UIMessageChunk` frames
// (`start` / `text-start` / `text-delta(id, delta)` / `text-end` /
// `source-url`; `data-confidence` and `data-refusal` carried under
// `data:{}`; `finish` constrained to the SDK union via
// `normalizeFinishReason()`) — see PR #90 (W3.3 BE wire format
// catch-up) — instead of one JSON response. SSE clients send
// `Accept: text/event-stream` (not `application/json`), and the
// default `auth` middleware redirects unauthenticated requests to
// `/login` (302 + HTML) which the streaming client can't parse.
// `auth.sse` (see bootstrap/app.php) returns JSON 401 instead so the
// SPA's auth bootstrap can re-establish the session and retry.
// v8.0.2 — AI Act gates (R-deep-review B): same conditional stack
// as the synchronous variant above, plus `auth.sse` instead of
// the implicit `auth` from the parent group. The middleware
// resolution is duplicated here (instead of lifted) because this
// route lives OUTSIDE the `auth` group so the `use ($chatPostMiddleware)`
// binding from the closure above is not in scope.
$aiActConsentFeatureSse = (string) config('ai-act-compliance.consent.gate_chat_feature', '');
// SECURITY (R30 / v8.0.3 C1): `tenant.authorize` runs right after `auth.sse`
// so a foreign `X-Tenant-Id` header is rejected before the streaming RAG
// retrieval reads another tenant's KB. Same guard as the synchronous
// conversations group above.
$chatSseMiddleware = ['auth.sse', 'tenant.authorize', 'redact-chat-pii', 'ai.disclosure'];
if ($aiActConsentFeatureSse !== '') {
    $chatSseMiddleware[] = 'ai.consent:' . $aiActConsentFeatureSse;
}
Route::post('/conversations/{conversation}/messages/stream', [MessageStreamController::class, 'store'])
    ->middleware($chatSseMiddleware);
Route::get('/agent-runs/{run}/events', AgentRunEventController::class)
    ->middleware(['auth.sse', 'tenant.authorize'])
    ->name('agent-runs.events');
Route::post('/agent-runs/{run}/cancel', [AgentRunControlController::class, 'cancel'])
    ->middleware(['auth', 'tenant.authorize'])
    ->name('agent-runs.cancel');
Route::post('/agent-runs/{run}/continue', [AgentRunControlController::class, 'resume'])
    ->middleware(['auth', 'tenant.authorize'])
    ->name('agent-runs.continue');

/*
|--------------------------------------------------------------------------
| React SPA (catch-all for /app/*)
|--------------------------------------------------------------------------
|
| Serves the React application. Authentication is handled inside React
| via `/api/auth/me` + guard components, so the route itself has no
| middleware — the SPA redirects to /login when the me endpoint returns
| 401. The legacy `/chat` Blade flow is untouched.
|
*/

Route::get('/app/{any?}', SpaController::class)
    ->where('any', '.*')
    ->name('spa');

// v6.0 — AI Act compliance scaffold. Until the external admin package
// ships a Laravel-13-compatible release, the direct mount URL redirects
// into the host SPA placeholder route under /app/admin/ai-act-compliance.
Route::middleware(['auth', 'can:viewAiActCompliance'])->get('/admin/ai-act-compliance/{any?}', function (?string $any = null) {
    $suffix = trim((string) $any, '/');
    $target = '/app/admin/ai-act-compliance';

    if ($suffix !== '') {
        $target .= '/'.$suffix;
    }

    return redirect($target);
})->where('any', '.*')->name('ai-act-compliance.spa');

/*
|--------------------------------------------------------------------------
| Testing-only endpoints (Playwright E2E)
|--------------------------------------------------------------------------
|
| Registered only when APP_ENV=testing. The controller also guards with
| `abort_unless(app()->environment('testing'), 403)` as defense in depth.
|
*/

if (app()->environment('testing')) {
    Route::post('/testing/reset', [TestingController::class, 'reset'])->name('testing.reset');
    Route::post('/testing/seed', [TestingController::class, 'seed'])->name('testing.seed');

    // Deterministic local API fixtures for the API-connector list→detail E2E
    // (R13): the route test/drill call is issued BY THE BACKEND, so it needs a
    // real local endpoint pair — a LIST (envelope `data` array-of-objects) and a
    // DETAIL (single object at /{id}). SSRF is relaxed for E2E in playwright.config.
    Route::get('/testing/api-fixture/users', fn () => response()->json([
        'data' => [
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.dev'],
            ['id' => 2, 'name' => 'Alan Turing', 'email' => 'alan@example.dev'],
        ],
    ]));
    Route::get('/testing/api-fixture/users/{id}', fn (string $id) => response()->json([
        'id' => (int) $id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.dev',
        'role' => 'admin',
    ]))->whereNumber('id');

    // Paginated list fixture for the workbench E2E: `?page=N` shifts the item ids
    // (so page 2 differs from page 1), a `meta.next_cursor` supports cursor
    // detection, and `?q=` flavours the first item's name for the search test.
    Route::get('/testing/api-fixture/paged', function (\Illuminate\Http\Request $request) {
        // Advance by page number OR by the cursor token from meta.next_cursor
        // (`cN` → page N), so both detection paths test as "distinct".
        $cursor = (string) $request->query('cursor', '');
        $page = $cursor !== '' && preg_match('/^c(\d+)$/', $cursor, $m) === 1
            ? (int) $m[1]
            : max(1, (int) $request->query('page', 1));
        $q = trim((string) $request->query('q', ''));
        $base = ($page - 1) * 2;

        return response()->json([
            'data' => [
                ['id' => $base + 1, 'name' => ($q !== '' ? "match {$q} " : '').'item '.($base + 1)],
                ['id' => $base + 2, 'name' => 'item '.($base + 2)],
            ],
            'meta' => ['page' => $page, 'next_cursor' => 'c'.($page + 1)],
        ]);
    });

    // Dependent-call fixture for the durable retrieval agent. The first route
    // resolves a human name to an id; the second returns two non-empty pages so
    // Playwright observes both logical chaining and physical pagination.
    Route::get('/testing/api-fixture/customers', function (\Illuminate\Http\Request $request) {
        $name = trim((string) $request->query('name', ''));
        if ($name === '503') {
            return response()->json(['error' => 'Temporary upstream failure.'], 503);
        }

        return response()->json(['items' => [[
            'id' => 77,
            'name' => $name !== '' ? $name : 'Tizio',
        ]]]);
    });
    Route::get('/testing/api-fixture/customers/{id}/orders', function (string $id, \Illuminate\Http\Request $request) {
        $page = max(1, (int) $request->query('page', 1));
        $orders = match ($page) {
            1 => [
                ['number' => 'A-100', 'customer_id' => (int) $id, 'total' => 120],
                ['number' => 'A-101', 'customer_id' => (int) $id, 'total' => 80],
            ],
            2 => [['number' => 'A-102', 'customer_id' => (int) $id, 'total' => 45]],
            default => [],
        };

        return response()->json([
            'orders' => $orders,
            'meta' => [
                'page' => $page,
                'locale' => $request->header('Accept-Language'),
            ],
        ]);
    })->whereNumber('id');
}

/*
|--------------------------------------------------------------------------
| Healthcheck (always on, intentionally tiny)
|--------------------------------------------------------------------------
|
| Used by Playwright's `webServer.url` poll. Lives outside both `auth`
| and `guest` middleware groups so it doesn't trigger redirect loops or
| view rendering that could 4xx/5xx and stall the boot probe. Returns
| a plain 200 with a stable body so the probe has an unambiguous green
| signal as soon as the framework is ready to serve.
|
*/

Route::get('/healthz', fn () => response('ok', 200, ['Content-Type' => 'text/plain']))
    ->name('healthz');

// CSP violation report collector (csp-report-collection). Public + throttled
// (browsers POST reports without credentials); inert 404 when no report_uri is
// configured. Bounded body + control-character-safe logging in the controller.
Route::post('/csp-report', [\App\Http\Controllers\CspReportController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('csp.report');

/*
|--------------------------------------------------------------------------
| KITT widget — pagina demo pubblica (non-SPA), solo local/testing
|--------------------------------------------------------------------------
|
| Pagina ospite di prova per il widget embeddabile: PUBBLICA (niente auth —
| il widget deve funzionare senza login, modello embed-key). Crea/riusa una
| WidgetKey demo per il tenant attivo e passa la public_key alla view. Gated
| a local/testing così non è esposta in produzione.
|
*/
// #44 — `testing` (E2E) monta sempre la demo; in `local` serve il flag
// ESPLICITO widget.demo_enabled (default OFF) così uno staging box lasciato a
// APP_ENV=local non conia una credenziale attiva per visitatori anonimi.
$widgetDemoAllowed = app()->environment('testing')
    || (app()->environment('local') && (bool) config('widget.demo_enabled'));
if ($widgetDemoAllowed) {
    /*
     * Demo-only host-backend boundary for authenticated widget users.
     * The browser calls this same-origin URL; the endpoint keeps ik_ server-side
     * and delegates the actual exchange to WidgetUserTokenController. It is
     * mounted only under the local/testing demo gate above.
     */
    Route::get('/widget-demo/user-token', function () {
        if (request()->boolean('fail')) {
            return response()->json([
                'error' => 'demo_identity_unavailable',
                'message' => 'The demo host could not authenticate this user.',
            ], 401)->header('Cache-Control', 'no-store');
        }

        $data = request()->validate([
            'key' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:120'],
        ]);
        $key = \App\Models\WidgetKey::query()
            ->where('public_key', $data['key'])
            ->firstOrFail();
        // The demo runs behind multiple PHP workers in E2E. Keep only this
        // hand-off credential in a cross-process store; the application's
        // default cache remains free to use the process-local `array` driver
        // for isolated rate-limit and lock state.
        $encryptedSecret = \Illuminate\Support\Facades\Cache::store('file')->get(
            'widget-demo:identity-secret:'.$key->id,
        );

        try {
            $identitySecret = is_string($encryptedSecret)
                ? \Illuminate\Support\Facades\Crypt::decryptString($encryptedSecret)
                : null;
        } catch (\Throwable) {
            $identitySecret = null;
        }

        if (! is_string($identitySecret)
            || ! is_string($key->identity_secret_hash)
            || ! \Illuminate\Support\Facades\Hash::check($identitySecret, $key->identity_secret_hash)) {
            return response()->json([
                'error' => 'demo_identity_unavailable',
                'message' => 'The demo identity credential is unavailable.',
            ], 503)->header('Cache-Control', 'no-store');
        }

        $origin = request()->getSchemeAndHttpHost();
        $exchangeRequest = \Illuminate\Http\Request::create(
            '/api/widget/user-token',
            'POST',
            ['subject' => $data['subject'], 'origin' => $origin],
            server: [
                'HTTP_X_WIDGET_KEY' => $key->public_key,
                'HTTP_AUTHORIZATION' => 'Bearer '.$identitySecret,
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        return app(\App\Http\Controllers\Api\Widget\WidgetUserTokenController::class)(
            $exchangeRequest,
            app(\App\Services\Widget\WidgetUserTokenService::class),
        )->header('Cache-Control', 'no-store');
    })->name('widget.demo.user-token');

    Route::get('/widget-demo', function () {
        $key = \App\Models\WidgetKey::firstOrCreate(
            ['public_key' => 'pk_demo_local'],
            [
                'tenant_id' => 'default',
                'project_key' => 'docs-v3',
                'label' => 'demo-local',
                'allowed_origins' => [
                    'http://127.0.0.1:8000',
                    'http://localhost:8000',
                    'http://localhost:5173',
                ],
                'rate_limit' => 1000,
                'skill' => 'askmydocs-assistant@1',
                'is_active' => true,
            ],
        );

        // ?mode=inline|fullscreen exercises both non-launcher layouts.
        $requestedMode = request()->query('mode');
        $mode = in_array($requestedMode, ['inline', 'fullscreen'], true)
            ? $requestedMode
            : 'helper';
        $userAuth = request()->boolean('user_auth');
        $subject = trim((string) request()->query('subject', 'demo-user'));
        if ($subject === '') {
            $subject = 'demo-user';
        }
        // Exercise the real cross-origin embed boundary locally. Browsers do
        // not send Origin on same-origin GET requests, while widget auth is
        // deliberately bound to the browser-supplied Origin header.
        $alternateApiHost = match (request()->getHost()) {
            '127.0.0.1' => 'localhost',
            'localhost' => '127.0.0.1',
            default => null,
        };
        $apiBase = $alternateApiHost === null
            ? ''
            : request()->getScheme().'://'.$alternateApiHost.':'.request()->getPort();

        $userTokenUrl = null;
        if ($userAuth) {
            $cachedSecret = \Illuminate\Support\Facades\Cache::store('file')->get(
                'widget-demo:identity-secret:'.$key->id,
            );
            try {
                $plainSecret = is_string($cachedSecret)
                    ? \Illuminate\Support\Facades\Crypt::decryptString($cachedSecret)
                    : null;
            } catch (\Throwable) {
                $plainSecret = null;
            }

            $key->refresh();
            $credentialIsUsable = is_string($plainSecret)
                && is_string($key->identity_secret_hash)
                && \Illuminate\Support\Facades\Hash::check($plainSecret, $key->identity_secret_hash);

            if (! $credentialIsUsable) {
                $credentials = app(\App\Services\Widget\WidgetIdentityCredentialService::class);
                $result = $key->user_auth_enabled
                    ? $credentials->rotate(
                        $key->id,
                        $key->tenant_id,
                        (int) $key->identity_credential_version,
                        null,
                        \App\Services\Widget\WidgetIdentityCredentialService::SURFACE_CLI,
                    )
                    : $credentials->enable(
                        $key->id,
                        $key->tenant_id,
                        (int) $key->identity_credential_version,
                        null,
                        \App\Services\Widget\WidgetIdentityCredentialService::SURFACE_CLI,
                    );
                $plainSecret = $result->plainSecret;
                \Illuminate\Support\Facades\Cache::store('file')->forever(
                    'widget-demo:identity-secret:'.$key->id,
                    \Illuminate\Support\Facades\Crypt::encryptString((string) $plainSecret),
                );
            }

            $userTokenUrl = route('widget.demo.user-token', [
                'key' => $key->public_key,
                'subject' => $subject,
                'fail' => request()->boolean('auth_failure') ? 1 : 0,
            ], false);
        }

        return view('widget-demo', [
            'publicKey' => $key->public_key,
            'apiBase' => $apiBase,
            'mode' => $mode,
            'userTokenUrl' => $userTokenUrl,
        ]);
    })->name('widget.demo');
}

// v8.0/W1.3 — one-click unsubscribe for email notifications.
// HMAC-signed token is the auth; no session / Sanctum guard required
// because the user is clicking from their mail client outside the
// browser session. See UnsubscribeTokenSigner for the token format
// and NotificationUnsubscribeController for the verification flow.
Route::get('/notifications/unsubscribe/{token}', \App\Http\Controllers\NotificationUnsubscribeController::class)
    ->name('notifications.unsubscribe')
    ->where('token', '[A-Za-z0-9_-]+');
