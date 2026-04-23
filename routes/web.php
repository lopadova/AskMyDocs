<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MessageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (guest only)
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    // React SPA serves the guest auth screens. The React router handles
    // /login, /forgot-password and /reset-password/{token} internally,
    // so every GET on these paths returns the same SPA shell. Direct
    // navigation / page refresh / password-reset email links all land
    // on the React flow. The POST endpoints below still feed the Blade
    // controllers as a no-JS fallback — the FormRequests introduced in
    // PR2 validate the same payload so both flows stay in sync.
    Route::get('/login', SpaController::class)->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    Route::get('/forgot-password', SpaController::class)->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');

    // Laravel's default password-reset notification points at
    // `/reset-password/{token}?email=…`. The SPA route
    // `/reset-password/$token` reads the token from the path param and
    // the email from the query. Keep the matching name so
    // `Password::sendResetLink` continues to generate the correct URL.
    Route::get('/reset-password/{token}', SpaController::class)->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Protected Routes (auth required)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Chat UI
    Route::get('/', fn () => redirect()->route('chat'))->name('home');
    Route::get('/chat/{conversation?}', [ChatController::class, 'index'])->name('chat');

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
