<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Agent\AmbiguousDocumentReference;
use App\Services\Kb\Agent\DocumentLineReader;
use App\Services\Kb\Agent\DocumentReferenceNotFound;
use App\Services\Kb\Agent\DocumentReferenceResolver;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read a canonical knowledge document and return its metadata plus chunks.')]
#[IsReadOnly]
#[IsIdempotent]
class KbReadDocumentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('Numeric ID of the knowledge document. Either document_id or ref is required.')
                ->nullable(),
            'ref' => $schema->string()
                ->description('Optional short reference (#3f9a1c2b4d5e) returned by other tools, instead of document_id.')
                ->nullable(),
            'from_line' => $schema->integer()
                ->description('Optional. Return only a LINE RANGE of the document, starting at this 1-based line (use a chunk\'s start_line from KbReadChunkTool). Omit both from_line and max_lines to get the whole document as chunks.')
                ->nullable(),
            'max_lines' => $schema->integer()
                ->description('Optional. Number of lines to return with from_line (capped by KB_LINE_READ_MAX_LINES).')
                ->nullable(),
        ];
    }

    public function handle(Request $request, ?DocumentLineReader $documentLineReader = null): Response
    {
        // R30 — scope the lookup to the MCP-resolved tenant so a client
        // bound to tenant A cannot read tenant B's documents by enumerating
        // the global auto-increment id. Mirrors every sibling KB MCP tool
        // (e.g. KbDocumentBySlugTool); the bare findOrFail here was the one
        // read-by-id path that bypassed the tenant boundary.
        $ref = $request->get('ref');
        if (is_string($ref) && trim($ref) !== '') {
            // W6 (qmd parity) — resolve a `#docid` short reference, tenant- and
            // ACL-scoped like the id lookup below; ambiguity is an error with
            // the candidates, never a silent first-row pick (R14).
            try {
                $document = app(DocumentReferenceResolver::class)->resolve(app(TenantContext::class)->current(), $ref);
            } catch (AmbiguousDocumentReference $e) {
                return Response::error('The reference matches several documents; use a longer prefix: '.implode(', ', array_map(
                    static fn (array $c): string => (string) $c['ref'].' '.(string) $c['source_path'],
                    $e->candidates,
                )));
            } catch (DocumentReferenceNotFound) {
                return Response::error('No readable document matches this reference.');
            }
            $document->load('chunks');
        } else {
            if (! is_numeric($request->get('document_id'))) {
                return Response::error('document_id (integer) or ref is required.');
            }
            $document = KnowledgeDocument::query()
                ->forTenant(app(TenantContext::class)->current())
                ->with('chunks')
                ->findOrFail((int) $request->get('document_id'));
        }

        // v8.44/W4 (ADR 0035 §D6.2) — opt-in line-range read. Without either
        // parameter the response is exactly what it has always been.
        $from = $request->get('from_line');
        $max = $request->get('max_lines');
        if ($from !== null || $max !== null) {
            $documentLineReader ??= app(DocumentLineReader::class);

            return Response::json([
                'document' => [
                    'id' => $document->id,
                    'project_key' => $document->project_key,
                    'title' => $document->title,
                    'source_path' => $document->source_path,
                    'source_type' => $document->source_type,
                ],
                'lines' => $documentLineReader->read(
                    $document,
                    is_numeric($from) ? (int) $from : 1,
                    is_numeric($max) ? (int) $max : null,
                ),
            ]);
        }

        return Response::json([
            'document' => [
                'id' => $document->id,
                'ref' => DocumentReferenceResolver::shortRef($document->document_hash),
                'project_key' => $document->project_key,
                'title' => $document->title,
                'source_path' => $document->source_path,
                'source_type' => $document->source_type,
                'metadata' => $document->metadata,
                'chunks' => $document->chunks
                    ->sortBy('chunk_order')
                    ->map(fn ($chunk) => [
                        'chunk_id' => $chunk->id,
                        'chunk_order' => $chunk->chunk_order,
                        'heading_path' => $chunk->heading_path,
                        'chunk_text' => $chunk->chunk_text,
                    ])->values()->all(),
            ],
        ]);
    }
}
