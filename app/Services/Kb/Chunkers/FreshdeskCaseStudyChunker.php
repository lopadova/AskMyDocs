<?php

declare(strict_types=1);

namespace App\Services\Kb\Chunkers;

use App\Connectors\Freshdesk\FreshdeskCaseStudyLineage;
use App\Services\Kb\Contracts\ChunkerInterface;
use App\Services\Kb\Pipeline\ChunkDraft;
use App\Services\Kb\Pipeline\ConvertedDocument;

final class FreshdeskCaseStudyChunker implements ChunkerInterface
{
    public function name(): string
    {
        return 'freshdesk-case-study';
    }

    public function supports(string $sourceType): bool
    {
        return $sourceType === FreshdeskCaseStudyLineage::SOURCE_TYPE;
    }

    public function chunk(ConvertedDocument $doc): array
    {
        $parts = explode("## Evidenze originali\n\n", $doc->markdown, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Freshdesk case evidence section is missing.');
        }
        $cap = max(1, (int) config('kb.chunking.hard_cap_tokens', 1024)) * 4;
        $summary = trim($parts[0]);
        if (strlen($summary) > $cap) {
            throw new \RuntimeException('Freshdesk case summary exceeds the atomic chunk budget.');
        }
        $chunks = [new ChunkDraft($summary, 0, 'Problema → Causa → Intervento → Esito', ['strategy' => $this->name(), 'case_section' => 'summary'])];
        $sections = preg_split('/(?=^### E[0-9]+ — )/mu', trim($parts[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($sections as $section) {
            if (strlen($section) > $cap) {
                // Keep each quotation whole; an oversized evidence chunk cannot silently lose its provenance.
                throw new \RuntimeException('Freshdesk case quotation exceeds the atomic chunk budget.');
            }
            $chunks[] = new ChunkDraft(trim($section), count($chunks), 'Evidenze originali', ['strategy' => $this->name(), 'case_section' => 'evidence']);
        }

        return $chunks;
    }
}
