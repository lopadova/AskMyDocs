<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

final class AgentRealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::channel('agent.activity.{tenant}.{userId}', static fn (User $user, string $tenant, string $userId): bool =>
            (string) $user->id === $userId && app(TenantContext::class)->current() === $tenant);
    }
}
