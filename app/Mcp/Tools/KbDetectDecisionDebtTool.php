<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\KbHealthService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Detect decision-doc debt candidates by health score threshold. Read-only.')]
#[IsReadOnly]
class KbDetectDecisionDebtTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()->required(),
            'min_score' => $schema->integer()->default(70),
            'limit' => $schema->integer()->default(50),
        ];
    }

    public function handle(Request $request, KbHealthService $health): Response
    {
        $projectKey = trim((string) $request->get('project_key'));
        if ($projectKey === '') {
            return Response::json(['error' => 'project_key is required', 'items' => []]);
        }

        $minScore = max(0, min((int) ($request->get('min_score') ?? 70), 100));
        $limit = max(1, min((int) ($request->get('limit') ?? 50), 200));

        $docs = KnowledgeDocument::query()
            ->accepted()
            ->byType('decision')
            ->where('project_key', $projectKey)
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get();

        $items = [];
        foreach ($docs as $doc) {
            $score = $health->score($doc);
            if (($score['health_score'] ?? 0) < $minScore) {
                continue;
            }
            $items[] = [
                'id' => $doc->id,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'title' => $doc->title,
                'health_score' => $score['health_score'],
                'factors' => $score['factors'],
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return Response::json([
            'project_key' => $projectKey,
            'min_score' => $minScore,
            'count' => count($items),
            'items' => $items,
        ]);
    }
}

