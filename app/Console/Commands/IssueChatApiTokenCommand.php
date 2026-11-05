<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Console\Command;

/** Operator bootstrap; plaintext is emitted once for private client storage. */
final class IssueChatApiTokenCommand extends Command
{
    protected $signature = 'chat:issue-api-token {email} {--tenant=} {--hours=24} {--debug}';
    protected $description = 'Issue an expiring, tenant-bound Bearer token for chat API automation';

    public function handle(): int
    {
        $tenant = trim((string) $this->option('tenant'));
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        if ($tenant === '' || $hours === false || $hours < 1 || $hours > 168) {
            $this->error('Provide --tenant and --hours between 1 and 168.');
            return self::INVALID;
        }
        $user = User::query()->whereEmailIdentity((string) $this->argument('email'))->where('is_active', true)->first();
        if ($user === null || ! ProjectMembership::query()->forTenant($tenant)->where('user_id', $user->id)->exists()) {
            $this->error('No active user with membership in the selected tenant.');
            return self::FAILURE;
        }
        $abilities = ['chat:use', 'chat:tenant:'.$tenant];
        if ($this->option('debug')) {
            if (! $user->hasAnyRole(['admin', 'super-admin'])) {
                $this->error('Debug access requires an administrator.');
                return self::FAILURE;
            }
            $abilities[] = 'chat:debug';
        }
        $expires = now()->addHours($hours);
        $token = $user->createToken('chat-api:'.$tenant, $abilities, $expires);
        $this->line(json_encode(['token' => $token->plainTextToken, 'tenant_id' => $tenant,
            'expires_at' => $expires->toIso8601String(), 'debug' => (bool) $this->option('debug')], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
