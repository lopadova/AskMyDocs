<?php

use App\Http\Controllers\Api\Admin\AdminInsightsController;
use App\Http\Controllers\Api\Admin\AdminNotificationDefaultsController;
use App\Http\Controllers\Api\Admin\ConnectorAdminController;
use App\Http\Controllers\Api\Admin\ComplianceReportController;
use App\Http\Controllers\Api\Admin\EvernoteEnexController;
use App\Http\Controllers\Api\Admin\DashboardMetricsController;
use App\Http\Controllers\Api\Admin\EvalHarnessUiBootstrapController;
use App\Http\Controllers\Api\Admin\KbDocumentController;
use App\Http\Controllers\Api\Admin\KbCollectionController;
use App\Http\Controllers\Api\Admin\KbHealthController;
use App\Http\Controllers\Api\Admin\KbPiiSettingController;
use App\Http\Controllers\Api\Admin\KbTreeController;
use App\Http\Controllers\Api\Admin\LogViewerController;
use App\Http\Controllers\Api\Admin\MaintenanceCommandController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\PiiStrategyController;
use App\Http\Controllers\Api\Admin\ProjectMembershipController;
use App\Http\Controllers\Api\Admin\McpServersAdminController;
use App\Http\Controllers\Api\Admin\McpTenantTokenController;
use App\Http\Controllers\Api\Admin\WidgetKeyAdminController;
use App\Http\Controllers\Api\Admin\WidgetSessionAdminController;
use App\Http\Controllers\Api\Admin\McpToolCallAuditController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\TabularReviewController;
use App\Http\Controllers\Api\Admin\TabularReviewStreamController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\WorkflowController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController as ApiPasswordResetController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\ChatFilterPresetController;
use App\Http\Controllers\Api\KbChatController;
use App\Http\Controllers\Api\ChatPreferencesController;
use App\Http\Controllers\Api\KbChunkFeedbackController;
use App\Http\Controllers\Api\KbCollectionPickerController;
use App\Http\Controllers\Api\KbDeleteController;
use App\Http\Controllers\Api\KbDocumentPreviewController;
use App\Http\Controllers\Api\KbDocumentSearchController;
use App\Http\Controllers\Api\KbIngestController;
use App\Http\Controllers\Api\KbPromotionController;
use App\Http\Controllers\Api\KbResolveWikilinkController;
use App\Http\Controllers\Api\Widget\WidgetSessionController;
use App\Http\Controllers\Api\Widget\WidgetSessionTokenController;
use App\Http\Controllers\Api\Widget\WidgetSetupController;
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

// Stateless auth for non-browser clients (the Tauri desktop demo): Bearer
// tokens, NO web session / CSRF. The cookie-based SPA keeps using the
// `web`-middleware group above. These routes deliberately sit OUTSIDE `web`
// so a token client without an XSRF cookie isn't rejected with 419 — the
// whole point of the Bearer flow is to avoid the cookie+CSRF handshake.
Route::prefix('auth')->group(function () {
    // Throttling lives in AuthController@token (failure-only counter), same
    // rationale as /login above.
    Route::post('/token', [AuthController::class, 'token'])
        ->name('api.auth.token');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/token/revoke', [AuthController::class, 'revokeToken'])
            ->name('api.auth.token.revoke');
    });
});

Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
])->group(function () {
    // v6.0 — AI Act compliance gates on the chat path:
    //  • `ai.disclosure` appends an X-AI-Disclosure response header
    //    (AI Act Art. 50). Always-on; opt-out via the package's
    //    `disclosure.enabled` config flag.
    //  • `ai.consent:<feature>` denies with 403 when no granted
    //    ConsentRecord exists for the named feature. Mounted only when
    //    the host opts in via `ai-act-compliance.consent.gate_chat_feature`
    //    (default null → middleware no-ops, preserving backward compat).
    $consentFeature = (string) config('ai-act-compliance.consent.gate_chat_feature', '');
    $chatMiddleware = ['ai.disclosure'];
    if ($consentFeature !== '') {
        $chatMiddleware[] = 'ai.consent:' . $consentFeature;
    }
    // `token.ability:kb:chat` is a no-op for the cookie SPA (TransientToken)
    // and only constrains Bearer PATs (the desktop demo): a token not scoped
    // for chat is rejected before it can burn provider quota (EnforceTokenAbility).
    Route::post('/kb/chat', KbChatController::class)
        ->middleware(array_merge($chatMiddleware, ['token.ability:kb:chat']));
    // v8.8.3 — anonymous-chat capability probe. Lets the SPA render the
    // "New anonymous chat" surface as a clean disabled landing (R14/R43)
    // when `kb.anonymous_chat.enabled` is off, instead of only learning
    // that after a 422-ed send. Carries the SAME $chatMiddleware as
    // POST /kb/chat so the probe stays consistent with the real chat
    // endpoint: when consent-gating (`ai.consent:<feature>`) is enabled the
    // probe is gated too (no "enabled=true" while the chat POST would 403),
    // and the AI-Act disclosure header is applied on this chat-adjacent route.
    Route::get('/kb/chat/anonymous-config', static function (): \Illuminate\Http\JsonResponse {
        return response()->json([
            'enabled' => (bool) config('kb.anonymous_chat.enabled', false),
        ]);
    })->middleware($chatMiddleware)->name('api.kb.chat.anonymous-config');
    Route::post('/kb/feedback', KbChunkFeedbackController::class)
        ->name('api.kb.feedback');
    // v8.8/W6 — chat-side related-graph: 1-hop neighbours of cited canonical docs.
    Route::get('/kb/related', [\App\Http\Controllers\Api\KbGraphController::class, 'related'])
        ->name('api.kb.related');
    Route::post('/kb/ingest', KbIngestController::class);
    // T2.6 — document title/path autocomplete for the FE chat composer's
    // @mention popover (T2.7/T2.8 will consume it).
    Route::get('/kb/documents/search', KbDocumentSearchController::class)
        ->middleware('token.ability:kb:read')
        ->name('api.kb.documents.search');
    Route::get('/kb/collections', KbCollectionPickerController::class)
        ->name('api.kb.collections.index');

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

    // Full source text of a CITED document, for the chat "open source" modal.
    // Reachable by every authenticated reader (not only admins) and scoped to
    // the caller's tenant + AccessScope — a citation can only open a document
    // the reader may see. {documentId} is numeric so it never shadows the literal
    // `/kb/documents/search` route above.
    Route::get('/kb/documents/{documentId}/preview', KbDocumentPreviewController::class)
        ->whereNumber('documentId')
        ->middleware('token.ability:kb:read')
        ->name('api.kb.documents.preview');

    // Promotion pipeline (ADR 0003 — human-gated). v4.2/W2 PR #116
    // refactored `promote` from inline write+dispatch to the 4-step
    // PromotionFlow saga with an explicit operator approval gate:
    //
    //   POST /api/kb/promotion/suggest             → LLM extracts candidates. Writes nothing.
    //   POST /api/kb/promotion/candidates          → validates a draft. Writes nothing.
    //   POST /api/kb/promotion/promote             → starts the PromotionFlow,
    //                                                pauses at the approval-gate
    //                                                step, returns 202 with a
    //                                                single-use approval token
    //                                                + approve/reject URLs.
    //                                                NO disk write yet.
    //   POST /api/kb/promotion/{approvalId}/approve → resumes the flow:
    //                                                writes canonical markdown
    //                                                to the KB disk + dispatches
    //                                                the ingest job.
    //   POST /api/kb/promotion/{approvalId}/reject  → halts the flow:
    //                                                disk stays untouched +
    //                                                rejected_promotion audit
    //                                                row written via FlowServiceProvider.
    //
    // Both approve and reject consume a single-use token (R21).
    Route::post('/kb/promotion/suggest', [KbPromotionController::class, 'suggest']);
    Route::post('/kb/promotion/candidates', [KbPromotionController::class, 'candidates']);
    Route::post('/kb/promotion/promote', [KbPromotionController::class, 'promote']);
    Route::post('/kb/promotion/{approvalId}/approve', [KbPromotionController::class, 'approve']);
    Route::post('/kb/promotion/{approvalId}/reject', [KbPromotionController::class, 'reject']);

    // v8.0.1 / deep-review F5 — per-user chat preferences. Server-side
    // persistence of toggles previously held in browser localStorage
    // (counterfactual panel today; future chat-level toggles go here
    // without a new endpoint).
    Route::get('/me/chat-preferences', [ChatPreferencesController::class, 'show'])
        ->name('api.me.chat-preferences.show');
    Route::patch('/me/chat-preferences', [ChatPreferencesController::class, 'update'])
        ->name('api.me.chat-preferences.update');

    // v8.15/W3 — per-user rich-digest preferences + the in-app digest feed.
    Route::get('/me/digest-preferences', [\App\Http\Controllers\Api\DigestPreferenceController::class, 'show'])
        ->name('api.me.digest-preferences.show');
    Route::put('/me/digest-preferences', [\App\Http\Controllers\Api\DigestPreferenceController::class, 'update'])
        ->name('api.me.digest-preferences.update');
    Route::get('/me/digest/latest', [\App\Http\Controllers\Api\DigestFeedController::class, 'latest'])
        ->name('api.me.digest.latest');

    // v8.15/W4 — per-user "your KB" dashboard (any authenticated user).
    Route::get('/me/dashboard', [\App\Http\Controllers\Api\UserDashboardController::class, 'show'])
        ->name('api.me.dashboard');
    // v8.15/W5 — per-user gamification badges (opt-in; empty when disabled).
    Route::get('/me/badges', [\App\Http\Controllers\Api\UserBadgesController::class, 'index'])
        ->name('api.me.badges');
    // v8.18/W4 — the caller's own AI coaching card (self-scoped; null/disabled-safe).
    Route::get('/me/coaching', [\App\Http\Controllers\Api\UserCoachingController::class, 'show'])
        ->name('api.me.coaching');
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
    'tenant.authorize',
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
        Route::get('/kb/health', [KbHealthController::class, 'index'])
            ->name('api.admin.kb.health.index');

        // v8.15/W1 — KB engagement analytics (R44 HTTP surface; R32 matrix row
        // for `/api/admin/engagement/summary`). Tenant-scoped reads.
        Route::get('/engagement/summary', [\App\Http\Controllers\Api\Admin\EngagementController::class, 'summary'])
            ->name('api.admin.engagement.summary');
        Route::get('/engagement/leaderboard', [\App\Http\Controllers\Api\Admin\EngagementController::class, 'leaderboard'])
            ->name('api.admin.engagement.leaderboard');
        // v8.15/W4 — engagement trend series for the admin charts.
        Route::get('/engagement/series', [\App\Http\Controllers\Api\Admin\EngagementController::class, 'series'])
            ->name('api.admin.engagement.series');

        // v8.18/W4 — AI gamification insights (project/tenant health narrative).
        // Read is admin|super-admin (group middleware); the on-demand REGENERATE
        // is super-admin only (R32) — it can fan out LLM calls across the tenant.
        Route::get('/engagement/insights', [\App\Http\Controllers\Api\Admin\GamificationInsightsController::class, 'show'])
            ->name('api.admin.engagement.insights');
        Route::post('/engagement/insights/regenerate', [\App\Http\Controllers\Api\Admin\GamificationInsightsController::class, 'regenerate'])
            ->middleware('role:super-admin')
            ->name('api.admin.engagement.insights.regenerate');

        // v8.15/W2 — digest preview (compose + render, no send). R32 matrix row
        // for `/api/admin/digest/preview`.
        Route::get('/digest/preview', [\App\Http\Controllers\Api\Admin\DigestController::class, 'preview'])
            ->name('api.admin.digest.preview');

        // Phase G2 — KB document detail (read-only). Admin-only binding
        // shim resolves trashed rows via `withTrashed()` — the default
        // Eloquent binding would 404 on a soft-deleted doc (R2). The
        // shim is registered inside the admin group so user-facing
        // routes continue to see the default-scoped model.
        // R30 — the binding MUST scope to the active tenant; otherwise an
        // admin in tenant A can resolve (show/raw/updateRaw/download/print/
        // history/graph/exportPdf/restore/destroy) a document owned by
        // tenant B by guessing its global id (IDOR). BelongsToTenant adds
        // no global read scope, so forTenant() is applied explicitly here.
        Route::bind('document', function ($id) {
            return \App\Models\KnowledgeDocument::withTrashed()
                ->forTenant(app(\App\Support\TenantContext::class)->current())
                ->findOrFail($id);
        });

        // R30 — same IDOR guard for project memberships, which otherwise
        // resolve via the default (unscoped) implicit binding.
        Route::bind('membership', function ($id) {
            return \App\Models\ProjectMembership::query()
                ->forTenant(app(\App\Support\TenantContext::class)->current())
                ->findOrFail($id);
        });

        // C4 (R30) — compliance reports are tenant-scoped at the binding so
        // verify / downloadJson / downloadPdf cannot resolve another
        // tenant's report by id.
        Route::bind('report', function ($id) {
            return \App\Models\ComplianceReport::query()
                ->forTenant(app(\App\Support\TenantContext::class)->current())
                ->findOrFail($id);
        });

        // v8.9 — KB upload (drag-and-drop) batches + items. R30 — tenant-scoped
        // binding so an admin in tenant A cannot inspect/commit/cancel or
        // delete an item of tenant B's batch by guessing the uuid (IDOR).
        Route::bind('uploadBatch', function ($id) {
            return \App\Models\KbIngestBatch::query()
                ->forTenant(app(\App\Support\TenantContext::class)->current())
                ->findOrFail($id);
        });
        Route::bind('uploadItem', function ($id, $route) {
            $query = \App\Models\KbIngestBatchItem::query()
                ->forTenant(app(\App\Support\TenantContext::class)->current());

            // When the route also carries {uploadBatch} (DELETE
            // …/{uploadBatch}/items/{uploadItem}), constrain the item to THAT
            // batch — otherwise a caller could pair an arbitrary same-tenant
            // item uuid with any batch id (an IDOR-in-tenant footgun). The
            // uploadBatch param precedes uploadItem in the URI, so it is
            // already the bound model here.
            $batch = $route->parameter('uploadBatch');
            if ($batch instanceof \App\Models\KbIngestBatch) {
                $query->where('batch_id', $batch->id);
            }

            return $query->findOrFail($id);
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
        Route::get('/compliance/reports', [ComplianceReportController::class, 'index'])
            ->name('api.admin.compliance.reports.index');
        Route::post('/compliance/reports', [ComplianceReportController::class, 'store'])
            ->name('api.admin.compliance.reports.store');
        Route::post('/compliance/reports/{report}/verify', [ComplianceReportController::class, 'verify'])
            ->name('api.admin.compliance.reports.verify');
        Route::get('/compliance/reports/{report}/json', [ComplianceReportController::class, 'downloadJson'])
            ->name('api.admin.compliance.reports.download_json');
        Route::get('/compliance/reports/{report}/pdf', [ComplianceReportController::class, 'downloadPdf'])
            ->name('api.admin.compliance.reports.download_pdf');

        // v8.9 — Admin RESTful CRUD on the `projects` registry. Per-tenant
        // scope (R30), int-typed `id` param keeps route binding plain.
        // No `show` route — the list carries everything the page needs.
        // R32 — covered by the AdminAuthorizationMatrix (`/api/admin/projects`).
        Route::apiResource('projects', \App\Http\Controllers\Api\Admin\ProjectController::class)
            ->parameters(['projects' => 'id'])
            ->only(['index', 'store', 'update', 'destroy'])
            ->names([
                'index' => 'api.admin.projects.index',
                'store' => 'api.admin.projects.store',
                'update' => 'api.admin.projects.update',
                'destroy' => 'api.admin.projects.destroy',
            ]);

        // v8.9 — Admin drag-and-drop KB upload: stage → review → commit →
        // poll. Reuses the exact Artisan ingest pipeline (IngestDocumentJob)
        // on commit. R32 — covered by the AdminAuthorizationMatrix
        // (`/api/admin/kb/uploads`). Bindings above scope every {uploadBatch}
        // / {uploadItem} to the active tenant (R30).
        Route::prefix('kb/uploads')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'index'])
                ->name('api.admin.kb.uploads.index');
            Route::post('/', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'store'])
                ->name('api.admin.kb.uploads.store');
            Route::get('/{uploadBatch}', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'show'])
                ->name('api.admin.kb.uploads.show');
            Route::get('/{uploadBatch}/status', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'status'])
                ->name('api.admin.kb.uploads.status');
            Route::post('/{uploadBatch}/commit', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'commit'])
                ->name('api.admin.kb.uploads.commit');
            Route::post('/{uploadBatch}/cancel', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'cancel'])
                ->name('api.admin.kb.uploads.cancel');
            Route::delete('/{uploadBatch}/items/{uploadItem}', [\App\Http\Controllers\Api\Admin\KbUploadController::class, 'destroyItem'])
                ->name('api.admin.kb.uploads.items.destroy');
        });

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

        // v8.7/W1 — Admin RESTful CRUD on kb_synonyms (Synonym Expansion).
        // Per-(tenant, project) scope; int-typed `id` param keeps route
        // binding plain (mirrors TagController). R32 — covered by the
        // AdminAuthorizationMatrix (`/api/admin/kb/synonyms`).
        Route::apiResource('kb/synonyms', \App\Http\Controllers\Api\Admin\SynonymController::class)
            ->parameters(['synonyms' => 'id'])
            ->names([
                'index' => 'api.admin.kb.synonyms.index',
                'store' => 'api.admin.kb.synonyms.store',
                'show' => 'api.admin.kb.synonyms.show',
                'update' => 'api.admin.kb.synonyms.update',
                'destroy' => 'api.admin.kb.synonyms.destroy',
            ]);

        // v8.7/W3–W4 — read-only AI document-change analyses (Doc Insights).
        // R32 — covered by the AdminAuthorizationMatrix (`/api/admin/kb/analyses`).
        Route::get('/kb/analyses', [\App\Http\Controllers\Api\Admin\KbDocAnalysisController::class, 'index'])
            ->name('api.admin.kb.analyses.index');

        // v8.8/W3 — per-(tenant, project) deep-analysis gate override.
        // R32 — covered by the AdminAuthorizationMatrix
        // (`/api/admin/kb/analysis-settings`).
        Route::get('/kb/analysis-settings', [\App\Http\Controllers\Api\Admin\KbAnalysisSettingController::class, 'index'])
            ->name('api.admin.kb.analysis-settings.index');
        Route::put('/kb/analysis-settings', [\App\Http\Controllers\Api\Admin\KbAnalysisSettingController::class, 'upsert'])
            ->name('api.admin.kb.analysis-settings.upsert');

        // v8.11/P10 — per-(tenant, project) Auto-Wiki gate override (auto-build).
        // R32 — covered by the AdminAuthorizationMatrix
        // (`/api/admin/kb/autowiki-settings`).
        Route::get('/kb/autowiki-settings', [\App\Http\Controllers\Api\Admin\KbAutoWikiSettingController::class, 'index'])
            ->name('api.admin.kb.autowiki-settings.index');
        Route::put('/kb/autowiki-settings', [\App\Http\Controllers\Api\Admin\KbAutoWikiSettingController::class, 'upsert'])
            ->name('api.admin.kb.autowiki-settings.upsert');

        // v8.8/W4 — content-gap analytics (questions the KB couldn't answer).
        // R32 — covered by the AdminAuthorizationMatrix
        // (`/api/admin/kb/content-gaps`).
        Route::get('/kb/content-gaps', [\App\Http\Controllers\Api\Admin\KbContentGapController::class, 'index'])
            ->name('api.admin.kb.content-gaps.index');
        Route::patch('/kb/content-gaps/{id}/resolve', [\App\Http\Controllers\Api\Admin\KbContentGapController::class, 'resolve'])
            ->whereNumber('id')->name('api.admin.kb.content-gaps.resolve');

        // v8.11/P1b — evidence-tier (AutoSci #67): taxonomy + human override.
        // R32 — covered by the AdminAuthorizationMatrix
        // (`/api/admin/kb/evidence-tiers`).
        Route::get('/kb/evidence-tiers', [\App\Http\Controllers\Api\Admin\KbEvidenceTierController::class, 'taxonomy'])
            ->name('api.admin.kb.evidence-tiers.index');
        Route::patch('/kb/documents/{id}/evidence-tier', [\App\Http\Controllers\Api\Admin\KbEvidenceTierController::class, 'update'])
            ->whereNumber('id')->name('api.admin.kb.documents.evidence-tier.update');

        // v8.11/P2 — auto-wiki graph canonicalization (AutoSci edges): rebuild a
        // doc's navigable graph (nodes + inferred edges). R32 — same admin KB
        // group gate as the representative `/api/admin/kb/evidence-tiers` row.
        Route::post('/kb/documents/{id}/wiki-link', [\App\Http\Controllers\Api\Admin\KbWikiLinkController::class, 'rebuild'])
            ->whereNumber('id')->name('api.admin.kb.documents.wiki-link');

        // v8.11/P3 — concept-page synthesis: sweep a project and synthesize
        // auto-tier domain-concept pages. R32 — same admin KB group gate as the
        // representative `/api/admin/kb/evidence-tiers` row.
        Route::post('/kb/concepts/synthesize', [\App\Http\Controllers\Api\Admin\KbConceptSynthesisController::class, 'synthesize'])
            ->name('api.admin.kb.concepts.synthesize');

        // v8.11/P4 — Auto-Wiki indices (index hub + project roll-ups) + the
        // auto-wiki operation log. R32 — same admin KB group gate as the
        // representative `/api/admin/kb/evidence-tiers` row.
        Route::post('/kb/wiki-index', [\App\Http\Controllers\Api\Admin\KbWikiIndexController::class, 'rebuild'])
            ->name('api.admin.kb.wiki-index.rebuild');
        Route::get('/kb/wiki-index', [\App\Http\Controllers\Api\Admin\KbWikiIndexController::class, 'show'])
            ->name('api.admin.kb.wiki-index.show');
        Route::get('/kb/wiki-operations', [\App\Http\Controllers\Api\Admin\KbWikiIndexController::class, 'operations'])
            ->name('api.admin.kb.wiki-operations');

        // v8.11/P5 — Auto-Wiki lint (dangling/orphan/stale/missing-index) + safe
        // auto-fix. R32 — same admin KB group gate as the representative
        // `/api/admin/kb/evidence-tiers` row.
        Route::get('/kb/wiki-lint', [\App\Http\Controllers\Api\Admin\KbWikiLintController::class, 'report'])
            ->name('api.admin.kb.wiki-lint.report');
        Route::post('/kb/wiki-lint/fix', [\App\Http\Controllers\Api\Admin\KbWikiLintController::class, 'fix'])
            ->name('api.admin.kb.wiki-lint.fix');

        // v8.11/P6 — agentic multi-hop graph navigation (BFS from seeds or index
        // anchors). R32 — same admin KB group gate as the representative
        // `/api/admin/kb/evidence-tiers` row.
        Route::post('/kb/wiki-navigate', [\App\Http\Controllers\Api\Admin\KbWikiNavigateController::class, 'navigate'])
            ->name('api.admin.kb.wiki-navigate');

        // v8.11/P7 — cross-model review of an auto-tier page. R32 — same admin KB
        // group gate as the representative `/api/admin/kb/evidence-tiers` row.
        Route::post('/kb/documents/{id}/wiki-review', [\App\Http\Controllers\Api\Admin\KbWikiReviewController::class, 'review'])
            ->whereNumber('id')->name('api.admin.kb.documents.wiki-review');

        // v8.11/P8 — apply engine: apply a change/delete suggestion (manual).
        // R32 — same admin KB group gate as `/api/admin/kb/evidence-tiers`.
        Route::post('/kb/analyses/{id}/apply', [\App\Http\Controllers\Api\Admin\KbApplySuggestionController::class, 'apply'])
            ->whereNumber('id')->name('api.admin.kb.analyses.apply');

        // v8.11/P9 — scheduled wiki maintenance, on-demand trigger. R32 — same
        // admin KB group gate as `/api/admin/kb/evidence-tiers`.
        Route::post('/kb/wiki-maintain', [\App\Http\Controllers\Api\Admin\KbWikiMaintainController::class, 'maintain'])
            ->name('api.admin.kb.wiki-maintain');

        // v8.11/P10 — Wiki Explorer: browse typed wiki pages (auto/human tier),
        // promote an auto page to the human-vouched tier, discard (soft-delete)
        // an auto page. R32 — `/api/admin/kb/wiki-pages` is the matrix row.
        Route::get('/kb/wiki-pages', [\App\Http\Controllers\Api\Admin\KbWikiExplorerController::class, 'index'])
            ->name('api.admin.kb.wiki-pages');
        Route::post('/kb/documents/{id}/wiki-promote', [\App\Http\Controllers\Api\Admin\KbWikiExplorerController::class, 'promote'])
            ->whereNumber('id')->name('api.admin.kb.documents.wiki-promote');
        Route::post('/kb/documents/{id}/wiki-discard', [\App\Http\Controllers\Api\Admin\KbWikiExplorerController::class, 'discard'])
            ->whereNumber('id')->name('api.admin.kb.documents.wiki-discard');

        // v8.7/W5 — Cloud Time Machine: version timeline + diff + restore.
        // R32 — covered by the AdminAuthorizationMatrix
        // (`/api/admin/kb/documents/1/versions`).
        Route::get('/kb/documents/{id}/versions', [\App\Http\Controllers\Api\Admin\KbDocumentVersionController::class, 'index'])
            ->whereNumber('id')->name('api.admin.kb.documents.versions.index');
        Route::get('/kb/documents/{id}/versions/diff', [\App\Http\Controllers\Api\Admin\KbDocumentVersionController::class, 'diff'])
            ->whereNumber('id')->name('api.admin.kb.documents.versions.diff');
        // `restore-version` (NOT `restore`) — `POST /kb/documents/{document}/restore`
        // already exists for un-deleting SOFT-DELETED docs (KbDocumentController);
        // the Time Machine restore re-activates an ARCHIVED VERSION, a distinct op
        // (R20 — route contracts must not collide).
        Route::post('/kb/documents/{id}/restore-version', [\App\Http\Controllers\Api\Admin\KbDocumentVersionController::class, 'restore'])
            ->whereNumber('id')->name('api.admin.kb.documents.versions.restore');

        Route::apiResource('kb/collections', KbCollectionController::class)
            ->parameters(['collections' => 'id'])
            ->names([
                'index' => 'api.admin.kb.collections.index',
                'store' => 'api.admin.kb.collections.store',
                'show' => 'api.admin.kb.collections.show',
                'update' => 'api.admin.kb.collections.update',
                'destroy' => 'api.admin.kb.collections.destroy',
            ]);
        Route::post('/kb/collections/{id}/members', [KbCollectionController::class, 'addMember'])
            ->whereNumber('id')
            ->name('api.admin.kb.collections.members.add');
        Route::post('/kb/collections/preview', [KbCollectionController::class, 'preview'])
            ->name('api.admin.kb.collections.preview');
        Route::get('/kb/collections/{id}/members', [KbCollectionController::class, 'members'])
            ->whereNumber('id')
            ->name('api.admin.kb.collections.members.index');
        Route::delete('/kb/collections/{id}/members/{documentId}', [KbCollectionController::class, 'removeMember'])
            ->whereNumber('id')
            ->whereNumber('documentId')
            ->name('api.admin.kb.collections.members.remove');

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
            // Every 200 or 403 writes an `admin_command_audit` row;
            // the 422 strategy-mismatch preflight is a config-stage
            // error and is intentionally not audited.
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

        // v8.0/W2.3 — Per-tenant notification defaults grid. Read open
        // to admin + super-admin (route group ACL); the controller
        // tightens the mutation path to super-admin only so platform
        // admins can audit the baselines without altering them.
        Route::prefix('notifications')->group(function () {
            Route::get('/defaults', [AdminNotificationDefaultsController::class, 'index'])
                ->name('api.admin.notifications.defaults.index');
            Route::put('/defaults', [AdminNotificationDefaultsController::class, 'update'])
                ->name('api.admin.notifications.defaults.update');
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

/*
|--------------------------------------------------------------------------
| Admin — PII redactor strategy (gate-protected, role-permissive)
|--------------------------------------------------------------------------
|
| v4.3/W1 sub-PR 4.5 — B4 — PII strategy admin endpoint.
|
| Mounted OUTSIDE the role-restricted /api/admin group above because the
| `viewPiiRedactorAdmin` Gate (registered in AppServiceProvider) admits
| three Spatie roles — `super-admin`, `dpo`, `admin`. Wrapping it under
| the `role:admin|super-admin` middleware would 403 the `dpo` role even
| though the Gate explicitly allows it. This mirrors the
| `padosoft/laravel-pii-redactor-admin` v1.0.2 mounting precedent
| (sub-PR 5): every PII admin route is gated by `can:viewPiiRedactorAdmin`
| only, never by Spatie roles.
|
| Defence in depth: even if the Gate definition shifts in a future
| AppServiceProvider edit, the explicit `can:` middleware on the route
| keeps the HTTP boundary aligned with the controller docblock contract.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:viewPiiRedactorAdmin',
])
    ->prefix('admin/pii')
    ->group(function () {
        Route::get('/strategy', [PiiStrategyController::class, 'show'])
            ->name('api.admin.pii.strategy');

        // v8.23 (Ciclo 4) — per-(tenant, project) PII ingestion policy. READ
        // rides the group's `viewPiiRedactorAdmin` gate (admin / dpo /
        // super-admin); WRITE adds `can:manageKbPiiPolicy` (dpo / super-admin)
        // so mutating the privacy posture is restricted to the data owners.
        Route::get('/policy', [KbPiiSettingController::class, 'index'])
            ->name('api.admin.pii.policy.index');
        Route::put('/policy', [KbPiiSettingController::class, 'upsert'])
            ->middleware('can:manageKbPiiPolicy')
            ->name('api.admin.pii.policy.upsert');
    });

/*
|--------------------------------------------------------------------------
| Admin — Connector framework (gate-protected, super-admin)
|--------------------------------------------------------------------------
|
| v4.5/W1 — Mounts the connector admin surface. The `manageConnectors`
| Gate (registered in `AppServiceProvider::registerConnectorGates()`)
| restricts every action to super-admin only. Cross-tenant isolation
| is enforced inside the controller via `TenantContext::current()`.
|
| The OAuth callback endpoint is mounted under the SAME group so
| Sanctum's session + the active tenant scope are available when the
| provider redirects back. Operators MUST initiate the install flow
| from the admin UI; bare callbacks without a valid `pending` row in
| the active tenant 404.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:manageConnectors',
])
    ->prefix('admin/connectors')
    ->group(function () {
        Route::get('/', [ConnectorAdminController::class, 'index'])
            ->name('api.admin.connectors.index');
        Route::get('/{name}/install', [ConnectorAdminController::class, 'startInstall'])
            ->name('api.admin.connectors.install');
        Route::get('/{name}/oauth/callback', [ConnectorAdminController::class, 'oauthCallback'])
            ->name('api.admin.connectors.oauth.callback');
        // v8.17 — credential-based connectors (IMAP): host-rendered form →
        // configure → ping/persist (basic) or redirect (xoauth2). Same gate group.
        Route::post('/{name}/configure', [ConnectorAdminController::class, 'configure'])
            ->name('api.admin.connectors.configure');
        // v8.20 — edit an existing account's metadata (label / project binding).
        Route::patch('/{installationId}', [ConnectorAdminController::class, 'update'])
            ->whereNumber('installationId')
            ->name('api.admin.connectors.update');
        // v8.21 (Ciclo 2) — per-account sync-run history (read-only).
        Route::get('/{installationId}/sync-runs', [\App\Http\Controllers\Api\Admin\IngestionController::class, 'syncRuns'])
            ->whereNumber('installationId')
            ->name('api.admin.connectors.sync-runs');
        Route::post('/{installationId}/sync-now', [ConnectorAdminController::class, 'syncNow'])
            ->whereNumber('installationId')
            ->name('api.admin.connectors.sync-now');
        Route::post('/{installationId}/disable', [ConnectorAdminController::class, 'disable'])
            ->whereNumber('installationId')
            ->name('api.admin.connectors.disable');
        Route::delete('/{installationId}', [ConnectorAdminController::class, 'destroy'])
            ->whereNumber('installationId')
            ->name('api.admin.connectors.destroy');

        // v4.5/W4 — Evernote-specific bulk import endpoint (.enex
        // export upload). Lives inside the same gate group as the
        // OAuth-driven endpoints so the `can:manageConnectors` ability
        // applies uniformly.
        Route::post('/evernote/import-enex', [EvernoteEnexController::class, 'importEnex'])
            ->name('api.admin.connectors.evernote.import-enex');
    });

/*
|--------------------------------------------------------------------------
| Admin — Ingestion & Sync observability (v8.21 / Ciclo 2, super-admin)
|--------------------------------------------------------------------------
|
| Read-only queue-depth + sync-run views for the "Ingestion & Sync"
| admin screen. Same `can:manageConnectors` allow-set (super-admin) as
| the connectors surface; the per-account sync-run history lives under
| the connectors group above.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:manageConnectors',
])
    ->prefix('admin/ingestion')
    ->group(function () {
        Route::get('/queue', [\App\Http\Controllers\Api\Admin\IngestionController::class, 'queue'])
            ->name('api.admin.ingestion.queue');
    });

/*
|--------------------------------------------------------------------------
| Admin — Runtime configuration governance (v8.22 / Ciclo 3, super-admin)
|--------------------------------------------------------------------------
|
| Read effective governable settings + set/clear per-(tenant, project)
| overrides without a deploy. Super-admin only (changes AI provider /
| cadence / runtime switches). Deploy-only keys are read-only (422 on set).
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'role:super-admin',
])
    ->prefix('admin/app-settings')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Admin\AppSettingsController::class, 'index'])
            ->name('api.admin.app-settings.index');
        Route::put('/', [\App\Http\Controllers\Api\Admin\AppSettingsController::class, 'update'])
            ->name('api.admin.app-settings.update');
    });

/*
|---------------------------------------------------------------------------
| Admin — MCP servers (v5.0/W1-W2)
|--------------------------------------------------------------------------
|
| Register external MCP servers and tune tools per tenant. Admin access
| is still locked behind the same SPA session + `auth:sanctum` surface.
| `manageMcpTools` remains super-admin by default (strictest blast radius
| until W4 adds per-user matrix + per-conversation overrides).
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:manageMcpTools',
])
    ->prefix('admin/mcp-servers')
    ->group(function () {
        Route::get('/', [McpServersAdminController::class, 'index'])
            ->name('api.admin.mcp-servers.index');
        Route::post('/', [McpServersAdminController::class, 'store'])
            ->name('api.admin.mcp-servers.store');
        Route::post('/{id}/handshake', [McpServersAdminController::class, 'handshake'])
            ->whereNumber('id')
            ->name('api.admin.mcp-servers.handshake');
        Route::patch('/{id}/tools', [McpServersAdminController::class, 'updateEnabledTools'])
            ->whereNumber('id')
            ->name('api.admin.mcp-servers.update-tools');
        Route::post('/{id}/disable', [McpServersAdminController::class, 'disable'])
            ->whereNumber('id')
            ->name('api.admin.mcp-servers.disable');
        Route::delete('/{id}', [McpServersAdminController::class, 'destroy'])
            ->whereNumber('id')
            ->name('api.admin.mcp-servers.destroy');
    });

Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:viewMcpAudit',
])
    ->get('/admin/mcp-tool-call-audit', [McpToolCallAuditController::class, 'index'])
    ->name('api.admin.mcp-tool-call-audit.index');

Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:manageMcpTools',
])
    ->prefix('admin/mcp/tokens')
    ->group(function () {
        Route::get('/', [McpTenantTokenController::class, 'index'])
            ->name('api.admin.mcp.tokens.index');
        Route::post('/', [McpTenantTokenController::class, 'store'])
            ->name('api.admin.mcp.tokens.store');
        Route::post('/{id}/revoke', [McpTenantTokenController::class, 'revoke'])
            ->whereNumber('id')
            ->name('api.admin.mcp.tokens.revoke');
    });

/*
|--------------------------------------------------------------------------
| Admin — Widget key management (M6.2, gate: manageWidgetKeys)
|--------------------------------------------------------------------------
|
| CRUD + rotate + revoke for WidgetKey, tenant-scoped (R30).
| Same middleware stack as MCP tokens: EncryptCookies + StartSession + auth:sanctum
| + tenant.authorize + can:manageWidgetKeys.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:manageWidgetKeys',
])
    ->prefix('admin/widget-keys')
    ->group(function () {
        Route::get('/', [WidgetKeyAdminController::class, 'index'])
            ->name('api.admin.widget-keys.index');
        Route::post('/', [WidgetKeyAdminController::class, 'store'])
            ->name('api.admin.widget-keys.store');
        Route::patch('/{id}', [WidgetKeyAdminController::class, 'update'])
            ->whereNumber('id')
            ->name('api.admin.widget-keys.update');
        Route::delete('/{id}', [WidgetKeyAdminController::class, 'destroy'])
            ->whereNumber('id')
            ->name('api.admin.widget-keys.destroy');
        Route::post('/{id}/rotate', [WidgetKeyAdminController::class, 'rotate'])
            ->whereNumber('id')
            ->name('api.admin.widget-keys.rotate');
        Route::post('/{id}/revoke', [WidgetKeyAdminController::class, 'revoke'])
            ->whereNumber('id')
            ->name('api.admin.widget-keys.revoke');
    });

/*
|--------------------------------------------------------------------------
| Admin — Widget session inspection (M6.3, gate: viewWidgetSessions)
|--------------------------------------------------------------------------
|
| Read-only session list + detail for admin inspection, tenant-scoped (R30).
| Gate `viewWidgetSessions` admits admin + super-admin (lower barrier
| than key management since sessions are read-only).
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:viewWidgetSessions',
])
    ->prefix('admin/widget-sessions')
    ->group(function () {
        Route::get('/', [WidgetSessionAdminController::class, 'index'])
            ->name('api.admin.widget-sessions.index');
        Route::get('/{id}', [WidgetSessionAdminController::class, 'show'])
            ->whereNumber('id')
            ->name('api.admin.widget-sessions.show');
    });

/*
|--------------------------------------------------------------------------
| Admin — Eval Harness UI bootstrap config (gate-protected)
|--------------------------------------------------------------------------
|
| v4.4/W3 Copilot iter 2 finding #2 — Eval Harness UI cross-mount
| bootstrap config endpoint.
|
| Returns the runtime config payload the cross-mounted
| `padosoft/eval-harness-ui` SPA needs to render in parity with the
| iframe predecessor (metric labels, polling intervals, locale,
| command-palette shortcut). Iter 1 hard-coded an empty payload
| host-side which diverged from `config/eval-harness-ui.php` (R9 +
| R14); this endpoint replays that config exactly so operators'
| tuned settings reach the FE.
|
| Mounted OUTSIDE the role-restricted /api/admin group above because
| the `eval-harness.viewer` Gate admits four Spatie roles —
| `super-admin`, `admin`, `dpo`, `editor`. Wrapping it under the
| `role:admin|super-admin` middleware would 403 `dpo` and `editor`
| even though the Gate explicitly allows them. Same mounting
| precedent as `admin/pii/strategy` above.
|
| The two `vendor/padosoft/eval-harness-ui` server-side fences
| (env flag + `eval-harness-ui.non-prod` middleware) only guard the
| package's blade routes at `/admin/eval-harness/{view?}` — they do
| NOT cover this host endpoint. The host-side `auth:sanctum` +
| `can:eval-harness.viewer` stack is the only defence here, which is
| correct: a viewer / anonymous request 403s before the payload is
| rendered, and the payload itself contains no secrets — only what
| the package config already exposes through `config/eval-harness-ui.php`.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:eval-harness.viewer',
])
    ->prefix('admin/eval-harness')
    ->group(function () {
        Route::get('/bootstrap-config', [EvalHarnessUiBootstrapController::class, 'show'])
            ->name('api.admin.eval-harness.bootstrap-config');
    });

/*
|--------------------------------------------------------------------------
| Admin — Tabular Reviews (v4.7/W1)
|--------------------------------------------------------------------------
|
| Spreadsheet-style document extraction. Mounted under
| `can:viewTabularReviews` so the `viewer` Spatie role can browse
| (read-only) alongside `admin` + `super-admin`. The controller enforces
| the read-only constraint for viewer at the action layer.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:viewTabularReviews',
])
    ->prefix('admin/tabular-reviews')
    ->group(function () {
        Route::get('/', [TabularReviewController::class, 'index'])
            ->name('api.admin.tabular-reviews.index');
        Route::post('/', [TabularReviewController::class, 'store'])
            ->name('api.admin.tabular-reviews.store');
        // `/prompt` MUST come before `/{id}` so the literal path is
        // matched first (otherwise "prompt" parses as an id and 404s).
        Route::post('/prompt', [TabularReviewController::class, 'suggestPrompt'])
            ->name('api.admin.tabular-reviews.prompt');
        Route::get('/{id}', [TabularReviewController::class, 'show'])
            ->whereNumber('id')
            ->name('api.admin.tabular-reviews.show');
        Route::patch('/{id}', [TabularReviewController::class, 'update'])
            ->whereNumber('id')
            ->name('api.admin.tabular-reviews.update');
        Route::delete('/{id}', [TabularReviewController::class, 'destroy'])
            ->whereNumber('id')
            ->name('api.admin.tabular-reviews.destroy');
        Route::post('/{id}/generate', [TabularReviewController::class, 'generate'])
            ->whereNumber('id')
            ->name('api.admin.tabular-reviews.generate');
        Route::post('/{id}/regenerate-cell', [TabularReviewController::class, 'regenerateCell'])
            ->whereNumber('id')
            ->name('api.admin.tabular-reviews.regenerate-cell');
        Route::post('/{id}/clear-cells', [TabularReviewController::class, 'clearCells'])
            ->whereNumber('id')
            ->name('api.admin.tabular-reviews.clear-cells');
    });

// v4.7/W3 — SSE streaming variant of `tabular-reviews/{id}/generate`.
// Hoisted out of the main group so it can use the `auth.sse`
// middleware (`App\Http\Middleware\AuthenticateForSse`) instead of
// the default `auth:sanctum`. NOTE: for /api/* requests the global
// `Exceptions::shouldRenderJsonWhen(...)` in bootstrap/app.php
// already forces JSON-401 on auth failure, so the practical
// difference between the two for THIS route is small. We still use
// `auth.sse` here for two reasons: (a) defence in depth — if a
// future bootstrap change narrows the global JSON-render rule, the
// dedicated middleware keeps streaming auth failures parseable; and
// (b) consistency with `MessageStreamController`'s route which lives
// under the web group where the global renderer does NOT apply and
// the default `auth` would actually emit 302+HTML. Same Gate as the
// sync sibling (`can:viewTabularReviews`) so RBAC stays identical;
// same tenant scoping enforced in the controller. The endpoint is
// POST (so the FE consumer is fetch-based SSE — readable-stream +
// manual parsing; the native browser `EventSource` is GET-only and
// not used here). Emits `cell` events as the extractor produces
// them. NOTE: the v4.7 GA SPA's TabularReviewShow page DOES NOT
// yet consume this route — its Generate button calls the
// synchronous `/generate` sibling. The SSE route is fully
// implemented + tested (`TabularReviewStreamControllerTest`
// covers happy stream + 4xx + error-event + cap), and the
// progressive-paint FE consumer ships in v4.7.x alongside the
// Glide Data Grid migration (ADR 0010 D1). External SSE consumers
// (custom UIs, notebooks, integrations) can already use this
// route today. Copilot iter 8 caught the previous comment's drift
// about 302+HTML which only applies to web routes; iter 9 caught
// the implication that the v4.7 GA UI already paints
// progressively (it does not).
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth.sse:sanctum',
    'can:viewTabularReviews',
])->post(
    '/admin/tabular-reviews/{id}/generate-stream',
    [TabularReviewStreamController::class, 'stream'],
)
    ->whereNumber('id')
    ->name('api.admin.tabular-reviews.generate-stream');

/*
|--------------------------------------------------------------------------
| Admin — Workflows (v4.7/W2)
|--------------------------------------------------------------------------
|
| Reusable prompt templates (assistant or tabular) + AI-suggested
| workflows from the tenant's KB. Mounted under `can:viewWorkflows`
| so `viewer` can browse the catalogue (read-only); `admin` +
| `super-admin` can mutate templates AND request LLM-backed
| suggestions (controller enforces these via `assertCanCreate()` /
| `assertCanSuggest()`).
|
| `viewer` is intentionally allowed to call `/{id}/hide` and
| `/{id}/hide` DELETE — those endpoints only mutate the per-user
| `hidden_workflows` table (cosmetic personal preference, not the
| underlying template) and the policy at
| `WorkflowService::hide() / unhide()` scopes every row to the
| caller's own user_id. Copilot iter 1 correctly flagged that the
| previous "viewer is read-only across the surface" comment was
| inaccurate; this is the corrected contract.
|
| Literal sub-paths (`/suggest`, `/from-proposal`) MUST come before
| the `/{id}` catch-all so the literal route is matched first. The
| `/share` and `/hide` routes are nested under `/{id}` and rely on
| `whereNumber('id')` to keep the dispatch unambiguous.
|
| Middleware (`EncryptCookies` + `StartSession`) mirrors every other
| admin route group in this file: AskMyDocs is a Sanctum-SPA + API
| hybrid where the React shell at `/admin/*` authenticates via a
| stateful session cookie. The whole admin surface — Dashboard
| metrics, Users, Roles, KB tree/explorer, Tabular Reviews, PII admin,
| Eval Harness, Connectors — uses the same triple
| (`EncryptCookies` + `StartSession` + `auth:sanctum`), and
| diverging here would force the FE to issue token-bearer auth on
| this surface alone. Copilot iter 5 flagged the stateful overhead;
| the consistency with the rest of the admin surface outweighs the
| overhead — the FE would otherwise need bespoke fetch wiring.
|
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
    'can:viewWorkflows',
])
    ->prefix('admin/workflows')
    ->group(function () {
        Route::get('/', [WorkflowController::class, 'index'])
            ->name('api.admin.workflows.index');
        Route::post('/', [WorkflowController::class, 'store'])
            ->name('api.admin.workflows.store');

        // Literal sub-paths before /{id}.
        Route::post('/suggest', [WorkflowController::class, 'suggest'])
            ->middleware('throttle:30,1')
            ->name('api.admin.workflows.suggest');
        Route::post('/from-proposal', [WorkflowController::class, 'fromProposal'])
            ->name('api.admin.workflows.from-proposal');

        Route::get('/{id}', [WorkflowController::class, 'show'])
            ->whereNumber('id')
            ->name('api.admin.workflows.show');
        Route::patch('/{id}', [WorkflowController::class, 'update'])
            ->whereNumber('id')
            ->name('api.admin.workflows.update');
        Route::delete('/{id}', [WorkflowController::class, 'destroy'])
            ->whereNumber('id')
            ->name('api.admin.workflows.destroy');

        Route::post('/{id}/share', [WorkflowController::class, 'share'])
            ->whereNumber('id')
            ->name('api.admin.workflows.share');
        Route::delete('/{id}/share', [WorkflowController::class, 'unshare'])
            ->whereNumber('id')
            ->name('api.admin.workflows.unshare');

        Route::post('/{id}/hide', [WorkflowController::class, 'hide'])
            ->whereNumber('id')
            ->name('api.admin.workflows.hide');
        Route::delete('/{id}/hide', [WorkflowController::class, 'unhide'])
            ->whereNumber('id')
            ->name('api.admin.workflows.unhide');
    });

// v7.0/W6.3.C — MCP internal callbacks (`/api/mcp/internal-auth` token
// probe + `/api/mcp/credentials` decrypted-secret callback) were both
// Node MCP sidecar artefacts. v7.0/W6.3.B retired the sidecar itself +
// removed `/credentials`; W6.3.C drops the surviving probe and
// `MCP_INTERNAL_AUTH_TOKEN` alongside it. The native MCP transports
// (HTTP / SSE / stdio) provided by `padosoft/askmydocs-mcp-pack` don't
// need any host-side internal callbacks.

/*
|--------------------------------------------------------------------------
| v8.0/W1.4 — Notification bell + /admin/notifications panel API
|--------------------------------------------------------------------------
|
| Per-user notification feed driven by the React NotificationBell +
| NotificationPanel components. Same EncryptCookies+StartSession+
| auth:sanctum surface as the rest of the SPA — no token-bearer
| auth path. Reads return only rows owned by the authenticated user
| in the active tenant; tenant-wide rows (user_id IS NULL) get a
| dedicated /api/notifications/system surface in W4 (decision-debt
| digest).
*/
Route::middleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Session\Middleware\StartSession::class,
    'auth:sanctum',
    'tenant.authorize',
])
    ->prefix('notifications')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\NotificationsController::class, 'index'])
            ->name('api.notifications.index');
        Route::get('/unread-count', [\App\Http\Controllers\Api\NotificationsController::class, 'unreadCount'])
            ->name('api.notifications.unread-count');
        // Copilot iter-4 #1 — R18 event-type discovery endpoint.
        Route::get('/event-types', [\App\Http\Controllers\Api\NotificationsController::class, 'eventTypes'])
            ->name('api.notifications.event-types');
        // v8.0/W2.2 — preferences grid backing endpoints.
        Route::get('/preferences', [\App\Http\Controllers\Api\NotificationPreferencesController::class, 'index'])
            ->name('api.notifications.preferences.index');
        Route::put('/preferences', [\App\Http\Controllers\Api\NotificationPreferencesController::class, 'update'])
            ->name('api.notifications.preferences.update');
        Route::post('/mark-all-read', [\App\Http\Controllers\Api\NotificationsController::class, 'markAllRead'])
            ->name('api.notifications.mark-all-read');
        Route::post('/{id}/mark-read', [\App\Http\Controllers\Api\NotificationsController::class, 'markRead'])
            ->whereNumber('id')
            ->name('api.notifications.mark-read');
        Route::post('/{id}/dismiss', [\App\Http\Controllers\Api\NotificationsController::class, 'dismiss'])
            ->whereNumber('id')
            ->name('api.notifications.dismiss');
    });

/*
|--------------------------------------------------------------------------
| Widget KITT — canale pubblico embeddabile (M1)
|--------------------------------------------------------------------------
|
| FUORI dallo stack auth:sanctum. L'accesso è governato da `widget.key`
| (modalità A browser pk+Origin / B proxy pk+secret-bearer): tenant + project
| sono risolti DALLA KEY, mai dal client (R30). Il CORS dedicato è gestito da
| HandleWidgetCors (prepended globale, scoped a api/widget/*), che risponde al
| preflight OPTIONS prima del routing. `throttle:120,1` è la guardia coarse
| pre-auth per IP; il limite fine per-key vive in ResolveWidgetKey. M2 aggiunge
| sessions/start, sessions/{session}/step, /exec-tool, /cancel, /replay.
|
*/
Route::middleware(['throttle:120,1', 'widget.key'])
    ->prefix('widget')
    ->group(function () {
        Route::get('/setup', WidgetSetupController::class)
            ->name('api.widget.setup');

        // M5.2 — conia un session token opzionale (modalità browser A)
        Route::post('/session-token', [WidgetSessionTokenController::class, 'mint'])
            ->name('api.widget.session-token');

        // M2 — loop ReAct: apertura sessione + turni. `{session}` è il
        // public_session_id (UUID), risolto scoping sulla key chiamante
        // dentro il controller (anti-IDOR, R30). /exec-tool + /replay in M4.
        Route::post('/sessions/start', [WidgetSessionController::class, 'start'])
            ->name('api.widget.sessions.start');
        Route::post('/sessions/{session}/step', [WidgetSessionController::class, 'step'])
            ->name('api.widget.sessions.step');
        // M4 — esecuzione BE AiTool. Il FE chiama questo quando l'orchestratore
        // emette una tool_call con is_be_tool=true.
        Route::post('/sessions/{session}/exec-tool', [WidgetSessionController::class, 'execTool'])
            ->name('api.widget.sessions.exec-tool');
        Route::post('/sessions/{session}/cancel', [WidgetSessionController::class, 'cancel'])
            ->name('api.widget.sessions.cancel');
        // M5.9 — replay endpoint: ritorna gli step con PII mascherata, scope per key.
        Route::get('/sessions/{session}/replay', [WidgetSessionController::class, 'replay'])
            ->name('api.widget.sessions.replay');
    });
