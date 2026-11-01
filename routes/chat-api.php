<?php

use App\Http\Controllers\Api as ChatApi;
use App\Http\Middleware\ChatApiConversationAccess;
use App\Http\Middleware\ChatApiTokenAccess;
use Illuminate\Support\Facades\Route;

// Same controllers and worker pipeline as the SPA, authenticated without cookies.
Route::prefix('chat')->name('api.chat.')
    ->middleware(['auth:sanctum', 'tenant.authorize', ChatApiTokenAccess::class, ChatApiConversationAccess::class])
    ->group(function () {
        $sendMiddleware = ['redact-chat-pii', 'ai.disclosure', 'throttle:kb-chat'];
        $consent = (string) config('ai-act-compliance.consent.gate_chat_feature', '');
        if ($consent !== '') $sendMiddleware[] = 'ai.consent:'.$consent;

        Route::get('/me', function (\Illuminate\Http\Request $request) {
            return response()->json([
                'schema_version' => 1, 'user_id' => $request->user()->id,
                'tenant_id' => app(\App\Support\TenantContext::class)->current(),
                'projects' => $request->user()->allowedProjects(),
                'locale' => $request->user()->locale, 'timezone' => config('app.timezone'),
                'expires_at' => $request->user()->currentAccessToken()->expires_at,
                'debug' => $request->user()->tokenCan('chat:debug') && $request->user()->hasAnyRole(['admin', 'super-admin']),
            ])->header('Cache-Control', 'private, no-store');
        })->name('me');
        Route::delete('/token', function (\Illuminate\Http\Request $request) {
            $request->user()->currentAccessToken()->delete();
            return response()->noContent();
        })->name('token.revoke');
        Route::get('/conversations', [ChatApi\ConversationController::class, 'index'])->name('conversations.index');
        Route::post('/conversations', [ChatApi\ConversationController::class, 'store'])->name('conversations.store');
        Route::get('/conversations/{conversation}/messages', [ChatApi\MessageController::class, 'index'])->name('messages.index');
        Route::post('/conversations/{conversation}/messages', [ChatApi\AgentMessageController::class, 'store'])
            ->middleware($sendMiddleware)->name('messages.store');
        Route::get('/conversations/{conversation}/messages/{message}/tool-sources/{execution}', ChatApi\ChatToolSourceController::class)
            ->whereNumber(['message', 'execution'])->name('tool-sources.show');
        Route::get('/conversations/{conversation}/debug-transcript', ChatApi\Admin\ConversationDebugTranscriptController::class)
            ->middleware(['token.ability:chat:debug', 'role:admin|super-admin'])->name('debug-transcript');
        Route::get('/documents/{documentId}/preview', ChatApi\KbDocumentPreviewController::class)->whereNumber('documentId')->name('documents.preview');
        Route::get('/runs/{run}', ChatApi\ChatApiRunController::class)->name('runs.show');
        Route::post('/runs/{run}/reconcile', ChatApi\ChatApiReconcileRunController::class)
            ->middleware(['token.ability:chat:debug', 'role:admin|super-admin'])->name('runs.reconcile');
        Route::get('/runs/{run}/events', ChatApi\AgentRunEventController::class)->name('runs.events');
        Route::post('/runs/{run}/cancel', [ChatApi\AgentRunControlController::class, 'cancel'])->name('runs.cancel');
        Route::post('/runs/{run}/continue', [ChatApi\AgentRunControlController::class, 'resume'])->name('runs.continue');
    });
