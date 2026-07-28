<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\SystemAdminAccessService;
use DomainException;
use Illuminate\Console\Command;

final class RevokeSystemAdminCommand extends Command
{
    protected $signature = 'system-admin:revoke
        {email : Existing active account email}
        {--yes : Confirm the platform-wide privilege change non-interactively}';

    protected $description = 'Revoke audited platform-wide access while retaining tenant super-admin access.';

    public function handle(SystemAdminAccessService $access): int
    {
        if (! $this->confirmed('Revoke platform-wide system administrator access?')) {
            return self::FAILURE;
        }

        try {
            $user = $access->revoke((string) $this->argument('email'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("System administrator access revoked from {$user->email} (#{$user->id}).");

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
