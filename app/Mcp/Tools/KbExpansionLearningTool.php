<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * W7 — MCP surface of the expansion learning loop (R44), READ-ONLY by design.
 *
 * Documented R44 exception: there is no MCP apply / train / promote tool.
 * Changing the live expansion behaviour, sending data to a training provider
 * or promoting a model is a human decision (ADR 0003's boundary applied to the
 * retrieval model) and stays on the CLI and the super-admin HTTP surface.
 * Admin / super-admin principals only; tenant from the MCP resolver (R30).
 * `KB_EXPANSION_LEARNING_ENABLED` OFF → `{disabled: true}`.
 */
#[Description('Read the status of the query-expansion learning loop: recorded expansion events, the applied tuning (per-type weights, curated examples), pending proposals with their benchmark gate result, and fine-tuning runs. Read-only; admin principals only.')]
#[IsReadOnly]
#[IsIdempotent]
class KbExpansionLearningTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Optional project key to restrict the status to.')
                ->nullable(),
        ];
    }

    public function handle(Request $request, ExpansionLearningService $expansionLearningService, TenantContext $tenants): Response
    {
        if (! $expansionLearningService->enabled()) {
            return Response::json(['disabled' => true, 'flag' => 'KB_EXPANSION_LEARNING_ENABLED']);
        }
        $user = Auth::user();
        if (! $user instanceof User || ! $user->hasAnyRole(['admin', 'super-admin'])) {
            return Response::error('The MCP token\'s principal may not read the expansion learning loop (requires admin or super-admin).');
        }
        $project = $request->get('project_key');

        return Response::json($expansionLearningService->status($tenants->current(), is_string($project) && $project !== '' ? $project : null));
    }
}
