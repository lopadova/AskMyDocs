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
        // Default Laravel 13 middleware stack — customize here if needed.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
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

        // TODO PR9 (Admin audit): when admin_command_audit migration ships,
        //   enable:
        //   $schedule->command('admin-audit:prune --days=365')
        //       ->dailyAt('04:30')->onOneServer()->withoutOverlapping();

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
    })
    ->create();
