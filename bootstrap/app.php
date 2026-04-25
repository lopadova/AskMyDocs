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
        // Embedding cache retention (default: 30 days, env KB_EMBEDDING_CACHE_RETENTION_DAYS)
        $schedule->command('kb:prune-embedding-cache')
            ->dailyAt('03:10')
            ->onOneServer()
            ->withoutOverlapping();

        // Chat log retention (default: 90 days, env CHAT_LOG_RETENTION_DAYS)
        $schedule->command('chat-log:prune')
            ->dailyAt('03:20')
            ->onOneServer()
            ->withoutOverlapping();

        // Soft-deleted document retention (default: 30 days,
        // env KB_SOFT_DELETE_RETENTION_DAYS). Hard-deletes rows that have
        // been soft-deleted longer than the retention window and removes
        // their original files from the KB disk.
        $schedule->command('kb:prune-deleted')
            ->dailyAt('03:30')
            ->onOneServer()
            ->withoutOverlapping();

        // Canonical graph consistency sweep. Dispatches one
        // CanonicalIndexerJob per canonical doc so kb_nodes + kb_edges
        // stay in sync even if a queue backlog or schema change has
        // left some docs with stale graph rows. No-op when no canonical
        // documents exist in any project.
        $schedule->command('kb:rebuild-graph')
            ->dailyAt('03:40')
            ->onOneServer()
            ->withoutOverlapping();

        // Rotate the failed_jobs table so a noisy week doesn't keep
        // growing the table forever. Default: drop rows older than 48h.
        $schedule->command('queue:prune-failed --hours=48')
            ->dailyAt('04:00')
            ->onOneServer()
            ->withoutOverlapping();

        // TODO PR3 (RBAC): when spatie/laravel-activitylog is installed,
        //   enable:
        //   $schedule->command('activitylog:clean --days=90')
        //       ->dailyAt('04:20')->onOneServer()->withoutOverlapping();

        // PR13 / Phase H2 — admin_command_audit forensic table rotation.
        // 1-year retention by default (env ADMIN_AUDIT_RETENTION_DAYS).
        $schedule->command('admin-audit:prune')
            ->dailyAt('04:30')
            ->onOneServer()
            ->withoutOverlapping();

        // PR13 / Phase H2 — confirm-token nonces cleanup (TTL 5min +
        // single-use). Default retention 1 day; env ADMIN_NONCE_RETENTION_DAYS.
        $schedule->command('admin-nonces:prune')
            ->dailyAt('04:50')
            ->onOneServer()
            ->withoutOverlapping();

        // NOTE: Laravel 13 does NOT ship a `notifications:prune` artisan
        //   command out of the box (only `notifications:table`). When the
        //   app installs a DatabaseNotification model that implements the
        //   `Prunable` trait, wire `model:prune --model=\\App\\Models\\DatabaseNotification`
        //   at 04:10 here instead.

        // Orphan scan (files on the KB disk with no matching DB row).
        // Runs in dry-run mode so nothing is deleted automatically —
        // operators review the output and run the command manually
        // without --dry-run if they want to purge the reported orphans.
        $schedule->command('kb:prune-orphan-files --dry-run')
            ->dailyAt('04:40')
            ->onOneServer()
            ->withoutOverlapping();

        // PR14 / Phase I — AI insights snapshot. Runs ONE pass per day
        // at 05:00 — LLM-bearing, so it is deliberately the last job
        // in the overnight window (lets the prune jobs stabilise the
        // corpus first) and --force is NOT passed (idempotent reruns
        // no-op, avoiding duplicate provider spend on retries).
        $schedule->command('insights:compute')
            ->dailyAt('05:00')
            ->onOneServer()
            ->withoutOverlapping();
    })
    ->create();
