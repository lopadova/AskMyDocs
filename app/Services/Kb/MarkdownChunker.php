<?php

namespace App\Services\Kb;

use Illuminate\Support\Collection;

/**
 * Implementazione placeholder.
 *
 * In produzione:
 * - usare parser Markdown AST-aware
 * - preservare heading path / breadcrumb
 * - chunkare per sezione e sottosezione
 * - rispettare token target / hard cap
 */
class MarkdownChunker
{
    /**
     * @return Collection<int, array{
     *     text:string,
     *     heading_path:?string,
     *     metadata:array<string,mixed>
     * }>
     */
    public function chunk(string $filename, string $markdown): Collection
    {
        // TODO:
        // sostituire questo stub con un chunker vero e AST-aware
        $parts = preg_split("/\n{2,}/", trim($markdown)) ?: [];

        return collect($parts)
            ->map(fn (string $part, int $i) => [
                'text' => trim($part),
                'heading_path' => null,
                'metadata' => [
                    'filename' => $filename,
                    'strategy' => 'placeholder_paragraph_split',
                    'order' => $i,
                ],
            ])
            ->filter(fn (array $chunk) => $chunk['text'] !== '')
            ->values();
    }
}
