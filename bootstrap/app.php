<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // v4.0/W1.D — ResolveTenant runs FIRST in every HTTP request so
        // every controller / service / scope sees the right tenant on
        // app(TenantContext::class)->current(). Defaults to 'default'
        // when no header / claim is present (R31 backward-compat with v3).
        $middleware->prepend(\App\Http\Middleware\ResolveTenant::class);

        // Route aliases exposed to routes/*.php and feature tests.
        //
        // `role` / `permission` / `role_or_permission` are Spatie's RBAC
        // filters — the package does NOT auto-register the alias in
        // Laravel 11+ bootstrap style, so we do it here. Without these,
        // `Route::middleware('role:admin')` throws `Target class [role]
        // does not exist.` at request-time.
        $middleware->alias([
            'project.access' => \App\Http\Middleware\EnsureProjectAccess::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'tenant.resolve' => \App\Http\Middleware\ResolveTenant::class,
            'mcp.scope' => \App\Http\Middleware\EnforceMcpScope::class,
            // v4.0/W3.1 — `auth` variant for SSE streaming routes that
            // returns JSON 401 instead of a 302 → /login redirect when
            // the session is expired. SSE clients send
            // `Accept: text/event-stream` (not application/json), so
            // the default `auth` middleware's redirect-on-no-session
            // behaviour produces an unparseable HTML response. Used by
            // `POST /conversations/{conversation}/messages/stream`.
            'auth.sse' => \App\Http\Middleware\AuthenticateForSse::class,
            // v4.1/W4.1.B — chat-PII redaction middleware. Wraps POST
            // /conversations/{conversation}/messages (sync + /stream
            // variant) only. Default no-op; redacts the request body's
            // `content` field via the package's RedactorEngine when
            // BOTH `kb.pii_redactor.enabled` AND
            // `kb.pii_redactor.persist_chat_redacted` are true.
            // Architecture-tested to be bound ONLY to those two routes
            // (admin / insights / kb ingest+delete routes are excluded).
            'redact-chat-pii' => \App\Http\Middleware\RedactChatPii::class,
            // v6.0 — EU AI Act compliance middleware. `ai.disclosure`
            // appends the Art. 50 disclosure response header on every
            // chat response (opt-out via the package's
            // `disclosure.enabled` config). `ai.consent:<feature>` gates
            // the route on a granted ConsentRecord for the given feature
            // key — host opts in by setting
            // `ai-act-compliance.consent.gate_chat_feature=chat` (off by
            // default to keep existing AskMyDocs users non-breaking).
            'ai.disclosure' => \Padosoft\AiActCompliance\Disclosure\AiDisclosureMiddleware::class,
            'ai.consent' => \Padosoft\AiActCompliance\Consent\RequireConsentMiddleware::class,
            // v6.1.1 — package v1.5 multi-tenancy. Resolves the active
            // sister-package Tenant from `X-Tenant-Id` header (or
            // `?tenant=` fallback) and binds it on the package's
            // `Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext`
            // request-scoped singleton. Suspended → 423 Locked,
            // archived → 410 Gone, unknown → 404. No header passes
            // through with the package context unset — host's own
            // `App\Support\TenantContext` (which always has a value)
            // remains the source of truth for non-AI-Act tenant
            // scoping. The two contexts live in parallel; the
            // `App\Compliance\TenantContextBridge` listens to the
            // host `ResolveTenant` middleware and propagates into the
            // sister-package context so config overrides resolve under
            // the same tenant the rest of AskMyDocs is using.
            'ai-act.tenant-context' => \Padosoft\AiActCompliance\MultiTenancy\Http\Middleware\TenantContextMiddleware::class,
        ]);

        // CSRF except list — `/testing/*` POST endpoints are env-gated
        // (only registered when APP_ENV=testing in routes/web.php) and
        // exclusively driven by Playwright's auth setup, which has no
        // way to acquire a CSRF token before its very first call. The
        // routes themselves are triple-locked: `app()->environment()`
        // check on the route registration, plus an `abort_unless` in
        // the controller. Skipping CSRF here is a controlled exception,
        // not a general loosening.
        $middleware->validateCsrfTokens(except: [
            'testing/*',
        ]);

        // (No custom api-stateful group — Laravel 11+ `$middleware->group()`
        // signature varies by minor; we set session/cookie middleware
        // inline on the route groups in routes/api.php instead. See the
        // `auth:sanctum` groups there which include EncryptCookies +
        // StartSession explicitly.)
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Force JSON responses for every /api/* request regardless of
        // Accept header. Default Laravel behavior redirects to /login
        // on AuthenticationException when the request "doesn't expect
        // JSON" — which is true for Playwright's APIRequestContext
        // (request fixture) since it doesn't auto-add
        // `Accept: application/json`. The redirect chain then resolves
        // to a 200 from the login form, which is the OPPOSITE of what
        // an API contract should signal.
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // v8.0/W2.4 — every host-side slot reads its cron + enabled
        // flag from `config('askmydocs.schedule.<slot>.*')`. The
        // defaults preserve the pre-W2.4 overnight rotation, so a
        // deployment that ignores every new env var keeps shipping
        // the same schedule. Per-tenant overrides land in W4
        // (Tier-2). Slot list lives on the registrar so PHPUnit can
        // exercise the config-driven branch directly.
        $registrar = new \App\Scheduling\TierOneSchedulerRegistrar;
        $registrar->register($schedule);

        // TODO PR3 (RBAC): when spatie/laravel-activitylog is installed,
        //   add 'activitylog_clean' => ['activitylog:clean --days=90', '20 4 * * *']
        //   to TierOneSchedulerRegistrar::SLOTS.

        // v4.5/W1 — Connector framework sync scheduler. Walks every
        // active installation each minute and dispatches a
        // ConnectorSyncJob for any whose cadence window has elapsed.
        // The per-installation cadence is read from `config/connectors.php`
        // (default 15 min, per-connector overrides supported). No-op
        // when the migration hasn't run yet. NOT routed through the
        // registrar — the package owns this registration; the Tier-1
        // env knobs only cover host-side slots.
        (new \Padosoft\AskMyDocsConnectorBase\Scheduling\SyncScheduler)->registerSchedules($schedule);

        // v4.3/W3 — Nightly eval-harness regression run. Two gates,
        // BOTH must be true for the cron to fire:
        //   - upstream `EVAL_NIGHTLY_ENABLED` (legacy v4.3 knob)
        //     gates REGISTRATION below. Read via
        //     `config('askmydocs.composite_gates.eval_nightly')` so
        //     scheduler registration AND the ops-widget endpoint
        //     consult the same cached value under
        //     `php artisan config:cache` (Copilot iter-6 — env() vs
        //     config() drift). The config key is bound to the same
        //     `env('EVAL_NIGHTLY_ENABLED')` lookup, so the operator's
        //     existing env var is still the source of truth.
        //   - inside the gate, the W2.4 Tier-1 slot
        //     (`SCHEDULE_EVAL_NIGHTLY_ENABLED` + `_CRON`) controls the
        //     cron expression and offers a per-host kill-switch
        //     without removing the upstream legacy knob.
        // Production live-runs need BOTH on: EVAL_NIGHTLY_ENABLED=true
        // AND SCHEDULE_EVAL_NIGHTLY_ENABLED=true (the latter is the
        // default).
        if ((bool) config('askmydocs.composite_gates.eval_nightly', false)) {
            $job = $registrar->registerSlot($schedule, 'eval_nightly', 'eval:nightly');
            if ($job !== null) {
                $job->runInBackground();
            }
        }

        // v6.1.1 — EU AI Act regulatory-feed daily poll. Same
        // composite gating pattern as eval:nightly above; the
        // upstream env `AI_ACT_REGULATORY_FEED_ENABLED` is read via
        // `config('askmydocs.composite_gates.ai_act_regulatory_poll')`
        // so scheduler + widget stay consistent under config:cache.
        if ((bool) config('askmydocs.composite_gates.ai_act_regulatory_poll', false)) {
            $registrar->registerSlot($schedule, 'ai_act_regulatory_poll', 'ai-act:regulatory-poll');
        }
    })
    ->create();
