<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Connectors\Imap\Backfill\ImapBackfillManager;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** MCP read/start surface over the same tenant-scoped backfill manager as HTTP. */
#[Description('Inspect, start, or resume a durable full-history IMAP import. action=status reads the latest campaign; action=start creates a campaign, returns an active one, or resumes the latest failed campaign from its saved UID checkpoints. Tenant-scoped; start is a super-admin write operation.')]
class KbImapBackfillTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'installation_id' => $schema->integer()
                ->description('The tenant-scoped IMAP connector installation id.')
                ->required(),
            'action' => $schema->string()
                ->description('Either status or start.')
                ->required(),
        ];
    }

    public function handle(
        Request $request,
        ImapBackfillManager $manager,
        TenantContext $tenants,
    ): Response {
        $installationId = (int) $request->get('installation_id');
        $action = strtolower(trim((string) $request->get('action')));
        if (! in_array($action, ['status', 'start'], true)) {
            return Response::json(['error' => 'action must be either status or start.']);
        }

        try {
            if ($action === 'start') {
                $manager->start($installationId);
            }

            return Response::json([
                'tenant_id' => $tenants->current(),
                'enabled' => $manager->isEnabled(),
                'backfill' => $manager->status($installationId),
            ]);
        } catch (HttpExceptionInterface $e) {
            return Response::json(['error' => $e->getMessage()]);
        }
    }
}
