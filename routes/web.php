<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\Api\ChatExtrasController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageStreamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (guest only)
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
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

    // Conversation AJAX endpoints (session auth, no Sanctum needed)
    Route::prefix('conversations')->group(function () use ($chatPostMiddleware) {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/', [ConversationController::class, 'store']);
        Route::patch('/{conversation}', [ConversationController::class, 'update']);
        Route::delete('/{conversation}', [ConversationController::class, 'destroy']);
        Route::get('/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/{conversation}/messages', [MessageController::class, 'store'])
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
$chatSseMiddleware = ['auth.sse', 'redact-chat-pii', 'ai.disclosure'];
if ($aiActConsentFeatureSse !== '') {
    $chatSseMiddleware[] = 'ai.consent:' . $aiActConsentFeatureSse;
}
Route::post('/conversations/{conversation}/messages/stream', [MessageStreamController::class, 'store'])
    ->middleware($chatSseMiddleware);

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
if (app()->environment(['local', 'testing'])) {
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

        return view('widget-demo', ['publicKey' => $key->public_key]);
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
