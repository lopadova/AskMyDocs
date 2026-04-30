<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TestingController;
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

    // Conversation AJAX endpoints (session auth, no Sanctum needed)
    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/', [ConversationController::class, 'store']);
        Route::patch('/{conversation}', [ConversationController::class, 'update']);
        Route::delete('/{conversation}', [ConversationController::class, 'destroy']);
        Route::get('/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/{conversation}/messages', [MessageController::class, 'store']);
        Route::post('/{conversation}/generate-title', [ConversationController::class, 'generateTitle']);
        Route::post('/{conversation}/messages/{message}/feedback', [FeedbackController::class, 'store']);
    });
});

// v4.0/W3.1 — SSE streaming variant of POST /messages, registered
// OUTSIDE the `auth` middleware group so we can apply our SSE-aware
// auth variant. Same conversation/auth/validation/filter contract as
// the synchronous route, but emits AI SDK protocol events
// (text-delta + source + data-confidence + data-refusal + finish)
// instead of one JSON response. SSE clients send
// `Accept: text/event-stream` (not `application/json`), and the
// default `auth` middleware redirects unauthenticated requests to
// `/login` (302 + HTML) which the streaming client can't parse.
// `auth.sse` (see bootstrap/app.php) returns JSON 401 instead so the
// SPA's auth bootstrap can re-establish the session and retry.
Route::post('/conversations/{conversation}/messages/stream', [MessageStreamController::class, 'store'])
    ->middleware('auth.sse');

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
