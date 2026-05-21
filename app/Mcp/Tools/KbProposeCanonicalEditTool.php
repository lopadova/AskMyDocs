<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\MarkdownDiff;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Validate a proposed canonical markdown edit and return parse/validation output + diff preview. Never writes.')]
#[IsReadOnly]
class KbProposeCanonicalEditTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_id' => $schema->string()->required(),
            'suggested_md' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, CanonicalParser $parser): Response
    {
        $docId = trim((string) $request->get('doc_id'));
        $suggestedMd = (string) $request->get('suggested_md');
        if ($docId === '' || $suggestedMd === '') {
            return Response::json(['error' => 'doc_id and suggested_md are required', 'valid' => false, 'errors' => ['missing_input']]);
        }

        $doc = KnowledgeDocument::query()
            ->canonical()
            ->where('doc_id', $docId)
            ->first();

        if (! $doc) {
            return Response::json(['error' => 'document_not_found', 'valid' => false, 'errors' => ['doc_not_found']]);
        }

        $parsed = $parser->parse($suggestedMd);
        if (! $parsed) {
            return Response::json(['valid' => false, 'errors' => ['missing_frontmatter'], 'diff' => MarkdownDiff::compute((string) ($doc->metadata['markdown'] ?? ''), $suggestedMd)]);
        }

        $validation = $parser->validate($parsed);

        return Response::json([
            'valid' => $validation->valid,
            'errors' => $validation->errors,
            'diff' => MarkdownDiff::compute((string) ($doc->metadata['markdown'] ?? ''), $suggestedMd),
        ]);
    }
}

