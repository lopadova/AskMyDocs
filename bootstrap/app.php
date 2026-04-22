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
    })
    ->create();
