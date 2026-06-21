<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\TabularCell;
use App\Models\TabularReview;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.19/W4 — MCP read surface (R44 third surface) for the Agentic Knowledge
 * Reports (tabular reviews). Returns the computed matrix of a saved report —
 * the columns + the per-document cells (value, flag, reasoning) — so an AI
 * client can ask "what is the governance status of the knowledge base?" and read
 * the deterministic `agent: graph` + LLM-extracted answers in one call.
 *
 * This is a READ over already-computed cells: generation (the LLM + graph
 * extraction) happens via the HTTP API (POST /api/admin/tabular-reviews/{id}/
 * generate) or the streaming variant — the same core. The MCP tool never
 * triggers an extraction, so it carries no LLM cost and is safe to call freely.
 *
 * R30: the review + its cells are tenant-scoped via `forTenant()`. R43: a
 * missing / cross-tenant review id returns `available:false`, never throws.
 */
#[Description('Read a saved Agentic Knowledge Report (tabular review) for the current tenant: its columns and the per-document cells (value, flag green|yellow|grey|red, reasoning), plus a flag-count summary. Pass review_id. Returns available:false when the report does not exist in this tenant. Read-only — never triggers an extraction.')]
#[IsReadOnly]
#[IsIdempotent]
class KbRunReportTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->integer()
                ->description('The id of the tabular review (Agentic Knowledge Report) to read.')
                ->required(),
            'max_rows' => $schema->integer()
                ->description('Cap the number of document rows returned (1–200). Defaults to 50.'),
        ];
    }

    public function handle(Request $request, TenantContext $tenants): Response
    {
        $tenant = $tenants->current();
        $reviewId = (int) $request->get('review_id');
        $maxRows = max(1, min(200, (int) ($request->get('max_rows') ?? 50)));

        $review = TabularReview::query()->forTenant($tenant)->find($reviewId);

        if ($review === null) {
            return Response::json([
                'available' => false,
                'review_id' => $reviewId,
                'report' => null,
            ]);
        }

        $columns = array_values(array_map(
            static fn (array $c): array => [
                'name' => (string) ($c['name'] ?? ''),
                'format' => (string) ($c['format'] ?? 'text'),
                'agent' => (string) ($c['agent'] ?? 'extract'),
                // Preserve the full agentic column identity — names are not a
                // stable contract for an MCP client (Copilot).
                'metric' => isset($c['metric']) && $c['metric'] !== '' ? (string) $c['metric'] : null,
            ],
            is_array($review->columns_config) ? $review->columns_config : [],
        ));

        // Cap at the QUERY level: resolve the distinct document ids first, take
        // the first N, and read ONLY those documents' cells — so a large report
        // never loads more than `max_rows` rows worth of cells (Copilot).
        $allDocIds = TabularCell::query()
            ->forTenant($tenant)
            ->where('review_id', $review->id)
            ->distinct()
            ->orderBy('document_id')
            ->pluck('document_id');
        $totalDocuments = $allDocIds->count();
        $pageDocIds = $allDocIds->take($maxRows)->all();

        $cells = $pageDocIds === [] ? collect() : TabularCell::query()
            ->forTenant($tenant)
            ->where('review_id', $review->id)
            ->whereIn('document_id', $pageDocIds)
            ->orderBy('document_id')
            ->orderBy('column_index')
            ->get();

        $rowsByDoc = [];
        // flag_counts reflects EXACTLY the returned page so the summary is always
        // consistent with `rows`.
        $flagCounts = ['green' => 0, 'yellow' => 0, 'grey' => 0, 'red' => 0];
        foreach ($cells as $cell) {
            $docId = (int) $cell->document_id;
            $content = is_array($cell->content) ? $cell->content : [];
            $flag = is_string($cell->flag) ? $cell->flag : null;
            if ($flag !== null && isset($flagCounts[$flag])) {
                $flagCounts[$flag]++;
            }
            $rowsByDoc[$docId] ??= ['document_id' => $docId, 'cells' => []];
            $rowsByDoc[$docId]['cells'][] = [
                'column_index' => (int) $cell->column_index,
                'summary' => $content['summary'] ?? null,
                'flag' => $flag,
                'reasoning' => isset($content['reasoning']) ? (string) $content['reasoning'] : '',
            ];
        }

        $rows = array_values($rowsByDoc);

        return Response::json([
            'available' => true,
            'review_id' => $review->id,
            'report' => [
                'title' => $review->title,
                'project_key' => $review->project_key,
                'columns' => $columns,
                'rows' => $rows,
                'summary' => [
                    // Returned rows vs the full document count — never inconsistent.
                    'documents' => count($rows),
                    'total_documents' => $totalDocuments,
                    'flag_counts' => $flagCounts,
                ],
            ],
        ]);
    }
}
