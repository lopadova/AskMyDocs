<?php

namespace App\Providers;

use App\Console\Commands\KbDeleteCommand;
use App\Console\Commands\KbIngestCommand;
use App\Console\Commands\KbIngestFolderCommand;
use App\Console\Commands\PruneChatLogsCommand;
use App\Console\Commands\PruneDeletedDocumentsCommand;
use App\Console\Commands\PruneEmbeddingCacheCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneEmbeddingCacheCommand::class,
                PruneChatLogsCommand::class,
                PruneDeletedDocumentsCommand::class,
                KbIngestCommand::class,
                KbIngestFolderCommand::class,
                KbDeleteCommand::class,
            ]);
        }
    }
}
