<?php

declare(strict_types=1);

namespace App\Services\Kb\Agent;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;

/**
 * v8.44/W4 (ADR 0035 §D6.2) — read a LINE RANGE of a document, so an agent can
 * go from a citation to the exact lines instead of pulling a whole document.
 *
 * The "document view" is the one the preview endpoint has always served: the
 * document's chunks in `chunk_order`, joined by a blank line. Line numbers are
 * 1-based within THAT view and stable for a given indexed version, so
 * `start_line`/`end_line` reported for a chunk ({@see chunkLines()}) address
 * the same text a later `from_line`/`max_lines` read returns.
 *
 * Tenant scope is the caller's job (it passes an already tenant-scoped,
 * ACL-scoped {@see KnowledgeDocument}); this class only reads that document's
 * chunks, also tenant-pinned (R30). Output is bounded by
 * `kb.line_read.max_lines` (default 400) — a read can never return an
 * unbounded document.
 */
final class DocumentLineReader
{
    /**
     * @return array{from_line: int, to_line: int, total_lines: int, truncated: bool, content: string}
     */
    public function read(KnowledgeDocument $document, int $fromLine = 1, ?int $maxLines = null): array
    {
        $cap = max(1, (int) config('kb.line_read.max_lines', 400));
        $from = max(1, $fromLine);
        $count = $maxLines === null ? $cap : max(1, min($maxLines, $cap));

        $lines = explode("\n", $this->view($document));
        $total = count($lines);

        if ($from > $total) {
            return ['from_line' => $from, 'to_line' => $from - 1, 'total_lines' => $total, 'truncated' => false, 'content' => ''];
        }

        $slice = array_slice($lines, $from - 1, $count);
        $to = $from + count($slice) - 1;

        return [
            'from_line' => $from,
            'to_line' => $to,
            'total_lines' => $total,
            'truncated' => $to < $total,
            'content' => implode("\n", $slice),
        ];
    }

    /**
     * The line range a chunk occupies in the document view, or null when the
     * chunk is not part of the document.
     *
     * @return array{start_line: int, end_line: int}|null
     */
    public function chunkLines(KnowledgeDocument $document, int $chunkId): ?array
    {
        $target = KnowledgeChunk::query()
            ->forTenant($document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->whereKey($chunkId)
            ->first(['id', 'chunk_order', 'chunk_text']);
        if ($target === null) {
            return null;
        }

        // Every chunk BEFORE the target in view order (chunk_order, then id)
        // contributes its own height (newlines + 1) plus the blank separator
        // line the view inserts after it. Computed as one aggregate so a read
        // never hydrates the document's text: memory stays O(target chunk).
        // The newline is bound, not written as char(10)/chr(10), so the same
        // SQL runs on SQLite and PostgreSQL.
        $preceding = KnowledgeChunk::query()
            ->forTenant($document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->where(fn ($q) => $q->where('chunk_order', '<', $target->chunk_order)
                ->orWhere(fn ($q) => $q->where('chunk_order', $target->chunk_order)->where('id', '<', $target->id)))
            ->selectRaw('COUNT(*) AS chunks, COALESCE(SUM(LENGTH(chunk_text) - LENGTH(REPLACE(chunk_text, ?, \'\'))), 0) AS newlines', ["\n"])
            ->toBase()
            ->first();

        $line = 1 + (int) ($preceding->newlines ?? 0) + 2 * (int) ($preceding->chunks ?? 0);
        $height = substr_count((string) $target->chunk_text, "\n") + 1;

        return ['start_line' => $line, 'end_line' => $line + $height - 1];
    }

    /**
     * The whole document view (chunks in order, blank-line separated) — the
     * same text {@see read()} slices. Used by the batch reader (W6 multi_get).
     */
    public function content(KnowledgeDocument $document): string
    {
        return $this->view($document);
    }

    private function view(KnowledgeDocument $document): string
    {
        return KnowledgeChunk::query()
            ->forTenant($document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->orderBy('chunk_order')
            ->orderBy('id')
            ->pluck('chunk_text')
            ->implode("\n\n");
    }
}
