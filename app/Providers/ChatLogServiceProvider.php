<?php

namespace App\Providers;

use App\Services\ChatLog\ChatLogManager;
use Illuminate\Support\ServiceProvider;

class ChatLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChatLogManager::class);
    }
}
