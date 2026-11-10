<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\Agent\DocumentBatchReader;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * W6 (qmd parity — `multi_get`) — MCP surface of the batch document read
 * (R44). Thin adapter over {@see DocumentBatchReader}: tenant from the MCP
 * resolver (R30), documents read under the MCP principal's ACL (R33), output
 * bounded and truncation declared (R14). `KB_MULTI_GET_ENABLED` OFF →
 * `{disabled: true}`. Returned content is document DATA, never instructions.
 */
#[Description('Read several knowledge-base documents in one call. Each selector is an exact source path (hr/leave.md), a glob over source paths (policies/**) or a short reference returned by other tools (#3f9a1c2b4d5e). Output is bounded by max_bytes; truncated, omitted and missing selectors are reported explicitly. Read-only; scoped to the documents you may read.')]
#[IsReadOnly]
#[IsIdempotent]
class KbMultiGetTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'selectors' => $schema->array()
                ->items($schema->string())
                ->description('1-20 selectors: exact source paths, globs or #short-refs.')
                ->required(),
            'project_key' => $schema->string()
                ->description('Optional project key to restrict the lookup to.')
                ->nullable(),
            'max_bytes' => $schema->integer()
                ->description('Optional total byte budget for the returned content (default 65536).')
                ->nullable(),
        ];
    }

    public function handle(Request $request, DocumentBatchReader $documentBatchReader, TenantContext $tenants): Response
    {
        if (! (bool) config('kb.multi_get.enabled', false)) {
            return Response::json(['disabled' => true, 'flag' => 'KB_MULTI_GET_ENABLED']);
        }

        $selectors = $request->get('selectors');
        if (! is_array($selectors)) {
            return Response::error('selectors must be an array of strings.');
        }
        $project = $request->get('project_key');
        $maxBytes = $request->get('max_bytes');

        try {
            return Response::json($documentBatchReader->read(
                $tenants->current(),
                array_map(static fn ($s): string => is_scalar($s) ? (string) $s : '', $selectors),
                is_string($project) && $project !== '' ? $project : null,
                is_numeric($maxBytes) ? max(1, min((int) $maxBytes, $documentBatchReader->maxBytesCeiling())) : null,
            ));
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
    }
}
