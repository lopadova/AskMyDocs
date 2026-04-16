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
        // Default Laravel 11 middleware stack — customize here if needed.
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
    })
    ->create();
