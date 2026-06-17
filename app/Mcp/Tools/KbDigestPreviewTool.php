<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Digest\AiDigestNarrator;
use App\Services\Digest\DigestComposer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.15/W2 — MCP read surface (R44) for the KB engagement digest: composes the
 * tenant's weekly/monthly digest (metrics + sections, optional AI narrative)
 * WITHOUT sending. Tenant-scoped via EnforceMcpScope (R30).
 */
#[Description('Compose (preview) the knowledge-base engagement digest for this tenant: metrics, new/promoted docs, docs needing review, top unanswered questions, and contributor leaderboard. Does not send anything.')]
#[IsReadOnly]
#[IsIdempotent]
class KbDigestPreviewTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'frequency' => $schema->string()
                ->description('Digest window: "weekly" (7d) or "monthly" (30d).')
                ->default('weekly'),
            'narrative' => $schema->boolean()
                ->description('Include the AI-written narrative summary (costs one LLM call).')
                ->default(false),
        ];
    }

    public function handle(Request $request, DigestComposer $composer, AiDigestNarrator $narrator): Response
    {
        $frequency = $request->get('frequency') === 'monthly' ? 'monthly' : 'weekly';

        $payload = $composer->composeForTenant($frequency);
        if ((bool) $request->get('narrative')) {
            $payload->narrative = $narrator->narrate($payload);
        }

        return Response::json($payload->toArray());
    }
}
