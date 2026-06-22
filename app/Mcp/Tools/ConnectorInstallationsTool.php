<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.20 — MCP read surface (R44) for connector installations / sync status.
 *
 * The third surface over the same capability as the HTTP
 * `GET /api/admin/connectors` index and the `connectors:list` Artisan command;
 * all three delegate to the SAME core {@see ConnectorInstallationService::summary()}.
 * Strictly tenant-scoped (R30) — an agent only ever sees its own tenant's
 * connected accounts.
 *
 * Multi-account: each connector reports a LIST of accounts (`label`,
 * `project_key` binding, `status`, `last_sync_at`, `error`). Degrades cleanly to
 * an empty roster when nothing is connected (R43 — no installations is a valid,
 * well-formed answer, not an error).
 */
#[Description('List this tenant\'s connector accounts and their sync status. Each connector reports its installed accounts (label, optional KB project binding, status pending/active/disabled/errored, last sync time, last error). Read-only; multi-account aware.')]
#[IsReadOnly]
#[IsIdempotent]
class ConnectorInstallationsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'connector' => $schema->string()
                ->description('Optional connector key (e.g. "imap", "google-drive") to restrict the roster to a single connector.'),
            'include_empty' => $schema->boolean()
                ->description('When true, also list connectors that have no connected accounts. Default false.')
                ->default(false),
        ];
    }

    public function handle(Request $request, ConnectorInstallationService $service, TenantContext $tenants): Response
    {
        $only = $request->get('connector');
        $includeEmpty = (bool) ($request->get('include_empty') ?? false);

        $connectors = [];
        $total = 0;

        foreach ($service->summary() as $entry) {
            if ($only !== null && $only !== '' && $entry['key'] !== $only) {
                continue;
            }

            $installations = $entry['installations'];
            $total += count($installations);

            if ($installations === [] && ! $includeEmpty) {
                continue;
            }

            $connectors[] = [
                'key' => $entry['key'],
                'display_name' => $entry['display_name'],
                'auth_kind' => $entry['auth_kind'],
                'installations' => $installations,
            ];
        }

        return Response::json([
            'tenant_id' => $tenants->current(),
            'total_installations' => $total,
            'connectors' => $connectors,
        ]);
    }
}
