<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\SystemAdminAccessService;
use DomainException;
use Illuminate\Console\Command;

final class GrantSystemAdminCommand extends Command
{
    protected $signature = 'system-admin:grant
        {email : Existing active account email}
        {--yes : Confirm the platform-wide privilege change non-interactively}';

    protected $description = 'Grant audited platform-wide system administrator access.';

    public function handle(SystemAdminAccessService $access): int
    {
        if (! $this->confirmed('Grant platform-wide system administrator access?')) {
            return self::FAILURE;
        }

        try {
            $user = $access->grant((string) $this->argument('email'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("System administrator access granted to {$user->email} (#{$user->id}).");

        return self::SUCCESS;
    }

    private function confirmed(string $question): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Explicit confirmation is required; re-run with --yes.');

            return false;
        }

        return $this->confirm($question, false);
    }
}
