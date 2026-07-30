<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Admin\TeamRegistryService;
use App\Support\PlatformAccess;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Operator/PHP surface (R44) for creating a team (= tenant): the `tenants`
 * registry row (name) + an initial project + a membership for the actor,
 * over the SAME {@see TeamRegistryService} the HTTP `POST /api/admin/teams`
 * uses. Unlike {@see CreateCompanyCommand} it does NOT mint a new admin
 * user — it attaches an EXISTING user as the first member.
 *
 *   php artisan team:create --name="Acme Corp"
 *   php artisan team:create --name="Acme Corp" --slug=acme --actor=admin@acme.com
 */
class CreateTeamCommand extends Command
{
    protected $signature = 'team:create
        {--name= : Team display name (required), e.g. "Acme Corp"}
        {--slug= : Team slug / tenant_id (default: slug of --name; a-z0-9_- , max 50)}
        {--actor= : Email or id of the user attached as the first member (default: first system-admin)}';

    protected $description = 'Create a new team (tenant): tenants row + initial project + membership, non-interactive.';

    public function handle(TeamRegistryService $teams): int
    {
        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('--name is required.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        try {
            $team = $teams->create(
                $this->option('slug') !== null ? (string) $this->option('slug') : null,
                $name,
                $actor,
            );
        } catch (TeamRegistryUnavailableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Team '{$team['name']}' created.");
        $this->table(['Field', 'Value'], [
            ['Name', $team['name']],
            ['Slug', $team['slug']],
            ['Hash', $team['hash']],
            ['First member', "{$actor->email} (#{$actor->id})"],
        ]);

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $ref = trim((string) $this->option('actor'));

        if ($ref !== '') {
            $user = ctype_digit($ref) ? User::find((int) $ref) : User::query()->where('email', $ref)->first();
            if ($user === null) {
                $this->error("Actor not found: {$ref}");
            }

            return $user;
        }

        $user = User::role(PlatformAccess::SYSTEM_ADMIN_ROLE, 'web')->first();
        if ($user === null) {
            $this->error('No system-admin found to attach — pass --actor=<email|id>.');
        }

        return $user;
    }
}
