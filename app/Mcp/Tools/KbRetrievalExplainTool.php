<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Kb\Retrieval\RetrievalExplainService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.41/W1 (ADR 0035 §D1) — MCP surface of the retrieval `explain` trace
 * (R44). Read-only. Thin adapter over {@see RetrievalExplainService}; the
 * tenant is the MCP-resolved {@see \App\Support\TenantContext}. Gated by
 * `KB_RETRIEVAL_EXPLAIN_ENABLED` (R43): OFF returns a structured
 * `{disabled: true}` answer, not an exception.
 *
 * Also role-gated (`admin` / `super-admin`), exactly like the HTTP route:
 * `#[IsReadOnly]` only maps to the baseline `mcp:read` token scope that every
 * valid MCP token carries, which is a different axis from a Laravel role —
 * the ranking internals are an operator diagnostic, not something every
 * tenant member holding a token should read (same posture as
 * {@see KbGetExportTool}).
 */
#[Description('Run a retrieval query and return, for each result, WHY it ranked where it did: the vector / full-text lists that produced it, the fusion score and the heuristic terms. Diagnostic and read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class KbRetrievalExplainTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural-language search query (max 500 characters).')
                ->required(),
            'project_key' => $schema->string()
                ->description('Optional project key filter.')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of results (capped by KB_RETRIEVAL_EXPLAIN_MAX_LIMIT).')
                ->default(8),
            'intent' => $schema->string()
                ->description('Optional disambiguation hint (see the search tools).')
                ->nullable(),
            'min_score' => $schema->number()
                ->description('Optional. Drop results scoring below this value (rerank score, else vector score).')
                ->nullable(),
            'line_numbers' => $schema->boolean()
                ->description('Add each chunk\'s line range in the document view (for KbReadDocumentTool from_line).')
                ->default(false),
            'investigation' => $schema->boolean()
                ->description('Trace the recursive investigation the chat runs (round queries, outcome, selected sources) instead of the direct retrieval.')
                ->default(false),
        ];
    }

    public function handle(Request $request, RetrievalExplainService $retrievalExplainService): Response
    {
        if (! $retrievalExplainService->enabled()) {
            return Response::json(['disabled' => true, 'flag' => 'KB_RETRIEVAL_EXPLAIN_ENABLED']);
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return Response::error('No MCP principal bound to this request.');
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            return Response::error('The MCP token\'s principal may not read ranking diagnostics (requires admin or super-admin).');
        }

        $query = trim((string) $request->get('query'));
        if ($query === '' || mb_strlen($query) > 500) {
            return Response::error('query must be 1-500 characters.');
        }

        $project = $request->get('project_key');

        return Response::json($retrievalExplainService->explain(
            $query,
            is_string($project) && $project !== '' ? $project : null,
            (int) ($request->get('limit') ?? 8),
            intent: is_string($request->get('intent')) ? $request->get('intent') : null,
            investigation: filter_var($request->get('investigation', false), FILTER_VALIDATE_BOOLEAN),
            minScore: is_numeric($request->get('min_score')) ? (float) $request->get('min_score') : null,
            lineNumbers: filter_var($request->get('line_numbers', false), FILTER_VALIDATE_BOOLEAN),
        ));
    }
}
