<?php

declare(strict_types=1);

namespace App\Mcp\Bridge;

use App\Models\User;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolAuthorizerContract;
use Padosoft\AskMyDocsMcpPack\Contracts\McpToolContract;

/**
 * v7.0/W1.B — Spatie-permission-backed implementation of the
 * package's {@see McpToolAuthorizerContract}, replacing the inline
 * `App\Mcp\Client\McpToolAuthorizer` shipped in v5.0/W4.
 *
 * Policy is intentionally simple at v7.0:
 *
 *   - The actor must be a `User` with role `admin` or `super-admin`.
 *   - No tool-name allow/deny lists are evaluated here — those live
 *     on `mcp_servers.enabled_tools_json` and are honoured upstream
 *     by {@see EloquentMcpServerAdapter::allowedTools()}.
 *
 * Concrete tool-by-tool deny-policy logic lands in a follow-up W4
 * slice with `mcp_tool_overrides` (see ROADMAP v5.0/W4).
 */
final class SpatieMcpToolAuthorizer implements McpToolAuthorizerContract
{
    public function authorize(mixed $actor, ?string $tenantId, McpToolContract $tool): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        return $actor->hasAnyRole(['admin', 'super-admin']);
    }
}
