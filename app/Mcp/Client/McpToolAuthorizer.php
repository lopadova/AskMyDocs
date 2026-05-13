<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use App\Models\McpServer;
use App\Models\User;

/**
 * v5.0/W4 scaffold — per-tool / per-user gate.
 *
 * Phase 1 keeps this permissive (`true` for authenticated users with
 * invoke permission). Concrete deny-policy logic lands in W4 with
 * database-backed override tables.
 */
final class McpToolAuthorizer
{
    public function canInvoke(User $user, McpServer $server, string $toolName): bool
    {
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            return false;
        }

        $enabled = $server->enabled_tools_json;
        if (! is_array($enabled) || $enabled === []) {
            return false;
        }

        return $enabled === ['*'] || in_array($toolName, $enabled, true);
    }
}
