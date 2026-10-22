<?php

namespace App\Providers;

use App\Ai\AiManager;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiManager::class);
        $this->app->singleton(\App\Ai\AgentChatModelScope::class);
    }

    public function boot(): void
    {
        $manifest = getenv('ASKMYDOCS_QA_MANIFEST');
        if (is_string($manifest) && $manifest !== '') {
            $this->app->make(\App\Services\Qa\InferenceLedger::class)->register($manifest);
        }
    }
}
