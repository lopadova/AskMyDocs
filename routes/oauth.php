<?php

use App\Http\Controllers\OAuthController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', \App\Http\Middleware\OAuthResponseHeaders::class])->group(function () {
    Route::get('/.well-known/oauth-protected-resource/mcp/knowledge/{connection}', [\App\Http\Controllers\McpConnectionController::class, 'metadata'])->whereUuid('connection');
    Route::get('/.well-known/oauth-authorization-server/oauth/mcp', [OAuthController::class, 'metadata']);
    Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'metadata']);
    Route::post('/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:oauth-register');
    Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:oauth-token')->name('oauth.token');
    Route::post('/oauth/revoke', [OAuthController::class, 'revoke'])->middleware('throttle:oauth-revoke')->name('oauth.revoke');
    Route::get('/oauth/userinfo', [OAuthController::class, 'userinfo'])->middleware('auth:sanctum')->name('oauth.userinfo');
});

Route::middleware(['web', \App\Http\Middleware\OAuthResponseHeaders::class])->group(function () {
    Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->middleware('throttle:oauth-authorize')->name('oauth.authorize');
    Route::get('/oauth/consent', SpaController::class)->name('oauth.consent');
    Route::get('/mcp/connect', SpaController::class);
    Route::get('/mcp/import-review/{import}', SpaController::class)->whereUuid('import');
    Route::get('/oauth/connections', SpaController::class)->name('oauth.connections');

    // Session-only: API keys must never approve new grants or manage others.
    Route::prefix('api/oauth')->middleware('auth:web')->group(function () {
        Route::get('/authorization/{authorization}', [OAuthController::class, 'consent']);
        Route::post('/authorization/{authorization}', [OAuthController::class, 'approve'])->middleware('throttle:oauth-authorize');
        Route::post('/mcp-connections', [\App\Http\Controllers\McpConnectionController::class, 'create'])->middleware('throttle:oauth-authorize');
        Route::get('/mcp-connections/{connection}/plugin.zip', [\App\Http\Controllers\McpConnectionController::class, 'package'])->whereUuid('connection');
        Route::get('/mcp-imports/{import}', [\App\Http\Controllers\McpConnectionController::class, 'review'])->whereUuid('import');
        Route::post('/mcp-imports/{import}/confirm', [\App\Http\Controllers\McpConnectionController::class, 'confirm'])->whereUuid('import')->middleware('throttle:oauth-authorize');
        Route::get('/connections', [OAuthController::class, 'connections']);
        Route::delete('/connections/{connection}', [OAuthController::class, 'disconnect'])->whereNumber('connection');
    });
});
