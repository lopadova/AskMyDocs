<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbPathContext;
use App\Models\User;
use App\Services\Kb\PathContext\PathContextService;
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
 * v8.44/W4 (ADR 0035 §D6.4) — read-only MCP view of the path contexts, so an
 * agent can see what each part of the corpus is described as before it
 * searches. Thin adapter over {@see PathContextService}, tenant-scoped (R30).
 *
 * Role-gated (`admin` / `super-admin`) like the HTTP surface: the list names
 * folders, and a baseline `mcp:read` token must not learn that a folder exists
 * (e.g. `hr/salaries`) that its principal could not retrieve from. Per-citation
 * contexts, by contrast, reach every reader — but only for documents that
 * reader can already retrieve.
 *
 * READ-ONLY BY DESIGN (documented R44 exception): a path context is text that
 * is rendered into the RAG prompt, so authoring it is an admin-only act over
 * the audited HTTP/CLI surfaces — an agent must not be able to write
 * prompt-visible text. `KB_PATH_CONTEXTS_ENABLED` OFF → `{disabled: true}`.
 */
#[Description('List the admin-authored descriptions of parts of the corpus (a project + source_path prefix → what it contains). Read-only; tenant-scoped. Answers {disabled: true} when the feature is off.')]
#[IsReadOnly]
#[IsIdempotent]
class KbPathContextsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Optional project key filter.')
                ->nullable(),
        ];
    }

    public function handle(Request $request, PathContextService $pathContextService, TenantContext $tenants): Response
    {
        if (! $pathContextService->enabled()) {
            return Response::json(['disabled' => true, 'flag' => 'KB_PATH_CONTEXTS_ENABLED']);
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return Response::error('No MCP principal bound to this request.');
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            return Response::error('The MCP token\'s principal may not list path contexts (requires admin or super-admin).');
        }

        $project = $request->get('project_key');
        $rows = $pathContextService->list($tenants->current(), is_string($project) && $project !== '' ? $project : null);

        return Response::json([
            'path_contexts' => $rows->map(fn (KbPathContext $c): array => [
                'project_key' => $c->project_key,
                'path_prefix' => $c->path_prefix,
                'description' => $c->description,
            ])->values()->all(),
        ]);
    }
}
