<?php

declare(strict_types=1);

namespace App\Mcp\Adapters;

use App\Models\User;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * v7.0/W6.3 — host adapter for the package's
 * {@see McpToolAuthorizerContract}.
 *
 * Wraps the existing host policy:
 *
 *   - Only users with `admin` or `super-admin` Spatie roles may
 *     invoke MCP tools at all.
 *   - Write tools (`McpToolContract::isReadOnly() === false`)
 *     require `super-admin` so RBAC stays strict for mutating
 *     operations even within the admin family.
 *   - System contexts (`$actor === null`) are denied by default —
 *     the host does not currently run agentic flows without a
 *     human in the loop; a future job-runner role can override.
 *
 * The `$actor` parameter is the host's user instance OR a string
 * id when the package was instantiated outside an HTTP request
 * (queue worker, console command). Both are handled.
 */
final class McpToolAuthorizerAdapter implements McpToolAuthorizerContract
{
    public function authorize(mixed $actor, ?string $tenantId, McpToolContract $tool): bool
    {
        // Tenant boundary is enforced UPSTREAM by the registry
        // adapter (which filters servers by tenant) + the host's
        // route middleware (which scopes the user's permitted
        // tenants). The `users` table doesn't carry a per-user
        // tenant_id in this repo, so the authorizer focuses on the
        // role-based + write/read split.
        $user = $this->resolveUser($actor);
        if ($user === null) {
            // No human actor → deny by default. System contexts
            // (queue workers) need an explicit override binding.
            return false;
        }

        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            return false;
        }

        // Stricter gate for write tools — admin can read, only
        // super-admin can mutate.
        if (! $tool->isReadOnly() && ! $user->hasRole('super-admin')) {
            return false;
        }

        return true;
    }

    private function resolveUser(mixed $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }
        if (is_int($actor) || (is_string($actor) && ctype_digit($actor))) {
            return User::query()->find((int) $actor);
        }
        return null;
    }
}
