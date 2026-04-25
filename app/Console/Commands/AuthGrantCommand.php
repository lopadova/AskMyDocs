<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

/**
 * Operator command: grant a Spatie role to a user, optionally wiring a
 * project membership at the same time.
 *
 * Idempotent. Safe to re-run:
 *   - assignRole() no-ops when the user already has the role.
 *   - ProjectMembership::firstOrCreate keyed on (user_id, project_key).
 *
 *   php artisan auth:grant alice@example.com admin
 *   php artisan auth:grant bob@example.com editor --project=hr-portal
 */
class AuthGrantCommand extends Command
{
    protected $signature = 'auth:grant
        {email : User email}
        {role : Role name}
        {--project= : Project key for an additional membership row}';

    protected $description = 'Grant a Spatie role (and optional project membership) to a user by email.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $roleName = (string) $this->argument('role');
        $projectKey = $this->option('project');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("User with email {$email} not found.");
            return self::FAILURE;
        }

        if (! $this->roleExists($roleName)) {
            $this->error("Role {$roleName} not found. Run db:seed --class=RbacSeeder first.");
            return self::FAILURE;
        }

        $user->assignRole($roleName);

        $this->line(sprintf(
            'Granted role "%s" to user "%s" (user_id=%d)',
            $roleName,
            $user->email,
            $user->id,
        ));

        if (is_string($projectKey) && $projectKey !== '') {
            $this->attachMembership($user, $projectKey);
        }

        return self::SUCCESS;
    }

    private function roleExists(string $name): bool
    {
        try {
            Role::findByName($name, 'web');
            return true;
        } catch (RoleDoesNotExist) {
            return false;
        }
    }

    private function attachMembership(User $user, string $projectKey): void
    {
        ProjectMembership::firstOrCreate(
            ['user_id' => $user->id, 'project_key' => $projectKey],
            ['role' => 'member', 'scope_allowlist' => null],
        );

        $this->line(sprintf('+ membership in project "%s"', $projectKey));
    }
}
