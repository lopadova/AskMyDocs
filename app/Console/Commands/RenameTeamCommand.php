<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\Exceptions\TeamRegistryUnavailableException;
use App\Services\Admin\TeamRegistryService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operator/PHP surface (R44) for renaming a team (= tenant): updates
 * `tenants.name`, over the SAME {@see TeamRegistryService} the HTTP
 * `PATCH /api/admin/teams/{slug}` uses. `slug` is the immutable tenant_id.
 *
 * Authorization runs through the service against the ACTOR (membership OR
 * tenant.cross-access); the default actor is the first super-admin (who has
 * cross-access), so an operator can rename any team. A team the actor may
 * not administer fails as "not found".
 *
 *   php artisan team:rename acme "Acme Corporation"
 *   php artisan team:rename acme "Acme Corporation" --actor=admin@acme.com
 */
class RenameTeamCommand extends Command
{
    protected $signature = 'team:rename
        {slug : The team slug (tenant_id) to rename}
        {name : The new display name}
        {--actor= : Email or id of the operator (default: first super-admin)}';

    protected $description = 'Rename a team (tenant): update its display name in the tenants registry.';

    public function handle(TeamRegistryService $teams): int
    {
        $slug = (string) $this->argument('slug');
        $name = (string) $this->argument('name');

        $actor = $this->resolveActor();
        if ($actor === null) {
            return self::FAILURE;
        }

        try {
            $team = $teams->rename($slug, $name, $actor);
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
        } catch (NotFoundHttpException $e) {
            $this->error("Team '{$slug}' not found or not manageable by the operator.");

            return self::FAILURE;
        }

        $this->info("Team '{$slug}' renamed to '{$team['name']}'.");

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

        $user = User::role('super-admin', 'web')->first();
        if ($user === null) {
            $this->error('No super-admin found — pass --actor=<email|id>.');
        }

        return $user;
    }
}
