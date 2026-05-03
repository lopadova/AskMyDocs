<?php

use App\Http\Controllers\Api\Admin\AdminInsightsController;
use App\Http\Controllers\Api\Admin\DashboardMetricsController;
use App\Http\Controllers\Api\Admin\KbDocumentController;
use App\Http\Controllers\Api\Admin\KbTreeController;
use App\Http\Controllers\Api\Admin\LogViewerController;
use App\Http\Controllers\Api\Admin\MaintenanceCommandController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\ProjectMembershipController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController as ApiPasswordResetController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\ChatFilterPresetController;
use App\Http\Controllers\Api\KbChatController;
use App\Http\Controllers\Api\KbDeleteController;
use App\Http\Controllers\Api\KbDocumentSearchController;
use App\Http\Controllers\Api\KbIngestController;
use App\Http\Controllers\Api\KbPromotionController;
use App\Http\Controllers\Api\KbResolveWikilinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sanctum SPA — Auth endpoints
|--------------------------------------------------------------------------
|
| Routes under routes/api.php are NOT in the `web` middleware group by
| default, so session + CSRF handling must be opted in explicitly. That's
| why the auth group below declares `web` middleware: Sanctum's
| EnsureFrontendRequestsAreStateful fires for requests under the `web`
| group, enabling the session cookie + XSRF-TOKEN round-trip the SPA needs.
|
*/
Route::middleware('web')->prefix('auth')->group(function () {
    // Login throttling is implemented in AuthController@login as a
    // failure-only counter (hit on bad credentials, clear on success) so
    // legitimate users are never rate-limited by their own success. The
    // route-level `throttle:login` middleware would rate-limit EVERY
    // request (success + failure) against a different cache key, causing
    // double-counting and spurious 429s — hence intentionally omitted.
    Route::post('/login', [AuthController::class, 'login'])
        ->name('api.auth.login');

    Route::post('/forgot-password', [ApiPasswordResetController::class, 'forgot'])
        ->middleware('throttle:forgot')
        ->name('api.auth.forgot');

    Route::post('/reset-password', [ApiPasswordResetController::class, 'reset'])
        ->name('api.auth.reset');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('api.auth.logout');

        Route::get('/me', [AuthController::class, 'me'])
            ->name('api.auth.me');

        Route::prefix('2fa')->group(function () {
            Route::post('/enable', [TwoFactorController::class, 'enable'])
                ->name('api.auth.2fa.enable');
            Route::post('/verify', [TwoFactorController::class, 'verify'])
                ->name('api.auth.2fa.verify');
            Route::post('/disable', [TwoFactorController::class, 'disable'])
                ->name('api.auth.2fa.disable');
        });
    });
});

Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
])->group(function () {
    Route::post('/kb/chat', KbChatController::class);
    Route::post('/kb/ingest', KbIngestController::class);
    // T2.6 — document title/path autocomplete for the FE chat composer's
    // @mention popover (T2.7/T2.8 will consume it).
    Route::get('/kb/documents/search', KbDocumentSearchController::class)
        ->name('api.kb.documents.search');

    // T2.9 — user-owned saved filter combinations (FE FilterBar dropdown
    // consumes these in T2.7-FE follow-up). Per-user authorization
    // enforced inside the controller via `where('user_id', auth()->id())`
    // on every action — no policy needed.
    Route::apiResource('/chat-filter-presets', ChatFilterPresetController::class)
        ->parameters(['chat-filter-presets' => 'id'])
        ->names('api.chat-filter-presets');
    Route::delete('/kb/documents', KbDeleteController::class);

    // Wikilink hover-card resolver for the React chat UI. Uses the
    // default-scoped KnowledgeDocument so soft-deletes + RBAC filter
    // apply automatically (R2).
    Route::get('/kb/resolve-wikilink', KbResolveWikilinkController::class)
        ->name('api.kb.resolve-wikilink');

    // Promotion pipeline (Phase 4). suggest + candidates write nothing;
    // only `promote` writes canonical markdown to the KB disk.
    Route::post('/kb/promotion/suggest', [KbPromotionController::class, 'suggest']);
    Route::post('/kb/promotion/candidates', [KbPromotionController::class, 'candidates']);
    Route::post('/kb/promotion/promote', [KbPromotionController::class, 'promote']);
});

/*
|--------------------------------------------------------------------------
| Admin — Dashboard metrics (Phase F1)
|--------------------------------------------------------------------------
|
| RBAC-guarded reads that feed the React admin shell. Spatie's
| `role:<name>|<name>` middleware accepts pipe-separated role names —
| admin OR super-admin is admitted; viewer / editor get 403.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'role:admin|super-admin',
])
    ->prefix('admin')
    ->group(function () {
        Route::get('/metrics/overview', [DashboardMetricsController::class, 'overview'])
            ->name('api.admin.metrics.overview');
        Route::get('/metrics/series', [DashboardMetricsController::class, 'series'])
            ->name('api.admin.metrics.series');
        Route::get('/metrics/health', [DashboardMetricsController::class, 'health'])
            ->name('api.admin.metrics.health');

        // Phase F2 — Users & Roles + Memberships.
        // `users` is soft-deletable, so the controller opts into
        // withTrashed()/onlyTrashed() explicitly; restore + force-delete
        // need their own named routes because apiResource can't express
        // "resolve trashed models via the route binding".
        Route::get('/users', [UserController::class, 'index'])->name('api.admin.users.index');
        Route::post('/users', [UserController::class, 'store'])->name('api.admin.users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('api.admin.users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('api.admin.users.update');
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('api.admin.users.destroy');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])
            ->whereNumber('id')
            ->name('api.admin.users.restore');
        Route::post('/users/{user}/resend-invite', [UserController::class, 'resendInvite'])
            ->name('api.admin.users.resend-invite');
        Route::patch('/users/{user}/active', [UserController::class, 'toggleActive'])
            ->name('api.admin.users.toggle-active');

        Route::apiResource('roles', RoleController::class)
            ->except(['update']);
        Route::match(['put', 'patch'], '/roles/{role}', [RoleController::class, 'update'])
            ->name('roles.update');

        Route::get('/permissions', [PermissionController::class, 'index'])
            ->name('api.admin.permissions.index');

        Route::get('/users/{user}/memberships', [ProjectMembershipController::class, 'index'])
            ->name('api.admin.users.memberships.index');
        Route::post('/users/{user}/memberships', [ProjectMembershipController::class, 'store'])
            ->name('api.admin.users.memberships.store');
        Route::patch('/memberships/{membership}', [ProjectMembershipController::class, 'update'])
            ->name('api.admin.memberships.update');
        Route::delete('/memberships/{membership}', [ProjectMembershipController::class, 'destroy'])
            ->name('api.admin.memberships.destroy');

        // Phase G1 — KB tree explorer. Browsing only; document detail +
        // source editor + graph/PDF live in G2/G3/G4 under their own
        // endpoints.
        Route::get('/kb/tree', [KbTreeController::class, 'index'])
            ->name('api.admin.kb.tree');
        Route::get('/kb/projects', [KbTreeController::class, 'projects'])
            ->name('api.admin.kb.projects');

        // Phase G2 — KB document detail (read-only). Admin-only binding
        // shim resolves trashed rows via `withTrashed()` — the default
        // Eloquent binding would 404 on a soft-deleted doc (R2). The
        // shim is registered inside the admin group so user-facing
        // routes continue to see the default-scoped model.
        Route::bind('document', function ($id) {
            return \App\Models\KnowledgeDocument::withTrashed()->findOrFail($id);
        });

        Route::apiResource('kb/documents', KbDocumentController::class)
            ->only(['show', 'destroy'])
            ->names([
                'show' => 'api.admin.kb.documents.show',
                'destroy' => 'api.admin.kb.documents.destroy',
            ]);
        Route::get('/kb/documents/{document}/raw', [KbDocumentController::class, 'raw'])
            ->name('api.admin.kb.documents.raw');
        // Phase G3 — updateRaw: PATCH writes the SPA-edited markdown,
        // records an `updated` audit row, then queues IngestDocumentJob
        // (the single ingestion execution path, CLAUDE.md §6).
        Route::patch('/kb/documents/{document}/raw', [KbDocumentController::class, 'updateRaw'])
            ->name('api.admin.kb.documents.update_raw');
        Route::get('/kb/documents/{document}/download', [KbDocumentController::class, 'download'])
            ->name('api.admin.kb.documents.download');
        Route::get('/kb/documents/{document}/print', [KbDocumentController::class, 'printable'])
            ->name('api.admin.kb.documents.print');
        Route::post('/kb/documents/{document}/restore', [KbDocumentController::class, 'restore'])
            ->name('api.admin.kb.documents.restore');
        Route::get('/kb/documents/{document}/history', [KbDocumentController::class, 'history'])
            ->name('api.admin.kb.documents.history');

        // Phase G4 — graph subgraph + PDF export. Both honour the
        // withTrashed() binding shim above, so the admin can render
        // the 1-hop graph of a trashed doc for forensic use, and
        // export a PDF of its last known body before it's pruned.
        Route::get('/kb/documents/{document}/graph', [KbDocumentController::class, 'graph'])
            ->name('api.admin.kb.documents.graph');
        Route::post('/kb/documents/{document}/export-pdf', [KbDocumentController::class, 'exportPdf'])
            ->name('api.admin.kb.documents.export_pdf');

        // T2.10 — Admin RESTful CRUD on kb_tags. Per-project scope,
        // cascade on delete via FK ON DELETE CASCADE on
        // knowledge_document_tags. Controller methods take `int $id`
        // (mirrors ChatFilterPresetController's int-typed params)
        // so route binding stays plain — no Eloquent implicit binding.
        Route::apiResource('kb/tags', \App\Http\Controllers\Api\Admin\TagController::class)
            ->parameters(['tags' => 'id'])
            ->names([
                'index' => 'api.admin.kb.tags.index',
                'store' => 'api.admin.kb.tags.store',
                'show' => 'api.admin.kb.tags.show',
                'update' => 'api.admin.kb.tags.update',
                'destroy' => 'api.admin.kb.tags.destroy',
            ]);

        // Phase H1 — Log Viewer (read-only). Five tabs: chat logs,
        // canonical audit, application log tail, activity log
        // (Spatie soft-dep), failed jobs. Write-path actions (retry
        // failed job, maintenance wizard, command runner) land in H2.
        Route::prefix('logs')->group(function () {
            Route::get('/chat', [LogViewerController::class, 'chat'])
                ->name('api.admin.logs.chat');
            Route::get('/chat/{id}', [LogViewerController::class, 'chatShow'])
                ->whereNumber('id')
                ->name('api.admin.logs.chat.show');
            // v4.1/W4.1.D — operator-driven detokenisation of a single
            // chat-log row. The controller enforces both prerequisites:
            // (a) `tokenise` strategy is configured (else 422), and
            // (b) the caller carries the Spatie permission named in
            // `kb.pii_redactor.detokenize_permission` (else 403).
            // Every call writes an `admin_command_audit` row.
            Route::post('/chat/{id}/detokenize', [LogViewerController::class, 'chatDetokenize'])
                ->whereNumber('id')
                ->name('api.admin.logs.chat.detokenize');
            Route::get('/canonical-audit', [LogViewerController::class, 'canonicalAudit'])
                ->name('api.admin.logs.canonical-audit');
            Route::get('/application', [LogViewerController::class, 'application'])
                ->name('api.admin.logs.application');
            Route::get('/activity', [LogViewerController::class, 'activity'])
                ->name('api.admin.logs.activity');
            Route::get('/failed-jobs', [LogViewerController::class, 'failedJobs'])
                ->name('api.admin.logs.failed-jobs');
        });

        // Phase I — AI insights. /latest + /{date} read the pre-computed
        // snapshot; /compute triggers a recompute (super-admin only +
        // throttle:3,5 because it burns provider quota);
        // /document/{id}/ai-suggestions returns per-doc tag proposals
        // on demand for the KB Meta tab (throttle:6,1 — one LLM call
        // per request).
        Route::prefix('insights')->group(function () {
            Route::get('/latest', [AdminInsightsController::class, 'latest'])
                ->name('api.admin.insights.latest');
            Route::post('/compute', [AdminInsightsController::class, 'compute'])
                ->middleware(['permission:commands.destructive', 'throttle:3,5'])
                ->name('api.admin.insights.compute');
            Route::get('/document/{documentId}/ai-suggestions', [AdminInsightsController::class, 'documentSuggestions'])
                ->whereNumber('documentId')
                ->middleware('throttle:6,1')
                ->name('api.admin.insights.document.ai-suggestions');
            // `/{date}` must come AFTER the literal prefixes above so
            // `/latest` and `/compute` aren't swallowed by the date
            // matcher.
            Route::get('/{date}', [AdminInsightsController::class, 'byDate'])
                ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
                ->name('api.admin.insights.by-date');
        });

        // Phase H2 — Maintenance command runner. Write-path actions
        // behind a strict whitelist + signed confirm_token for
        // destructive commands. /run is rate-limited per authenticated
        // user (throttle:10,1) so a rogue admin can't DoS the worker.
        // Every invocation writes an AdminCommandAudit row BEFORE
        // Artisan::call() runs — forensic trail survives even a
        // crashing command.
        Route::prefix('commands')->group(function () {
            Route::get('/catalogue', [MaintenanceCommandController::class, 'catalogue'])
                ->name('api.admin.commands.catalogue');
            Route::post('/preview', [MaintenanceCommandController::class, 'preview'])
                ->name('api.admin.commands.preview');
            Route::post('/run', [MaintenanceCommandController::class, 'run'])
                ->middleware('throttle:10,1')
                ->name('api.admin.commands.run');
            Route::get('/history', [MaintenanceCommandController::class, 'history'])
                ->name('api.admin.commands.history');
            Route::get('/scheduler-status', [MaintenanceCommandController::class, 'schedulerStatus'])
                ->name('api.admin.commands.scheduler-status');
        });
    });
