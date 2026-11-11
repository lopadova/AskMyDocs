<?php

declare(strict_types=1);

namespace App\Services\Kb\Knowledge;

use App\Connectors\Freshdesk\FreshdeskCaseStudyLineage;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\EmailTriage\EmailConnectorRegistry;

final class KnowledgeSource
{
    /** Original text only; generated summaries and triage cards are never evidence. */
    public function parts(KnowledgeDocument $doc): array
    {
        if ($doc->status !== 'active' || $doc->deleted_at !== null || data_get($doc->metadata, 'autowiki_concept') || data_get($doc->metadata, 'generated_fixture') === true) return [];
        // Generated canonical prose cannot become independent evidence for itself.
        // OCR/raw text is still an original source; Freshdesk has verified original lineage below.
        if ($doc->is_canonical && $doc->generation_source === 'auto' && $doc->source_type !== FreshdeskCaseStudyLineage::SOURCE_TYPE) return [];
        $chunks = KnowledgeChunk::query()->forTenant($doc->tenant_id)->where('knowledge_document_id', $doc->id)->orderBy('chunk_order')->get();
        if ($doc->source_type === FreshdeskCaseStudyLineage::SOURCE_TYPE) {
            $original = app(FreshdeskCaseStudyLineage::class)->originalEvidence($doc);
            return $original ? [['text' => $original, 'chunk_id' => $chunks->first()?->id, 'heading' => 'Original ticket evidence']] : [];
        }
        if (EmailConnectorRegistry::supportsKey((string) data_get($doc->metadata, 'connector')) && data_get($doc->metadata, 'email_triage.evaluation_id') && ! data_get($doc->metadata, 'attachment_filename')) {
            $original = app(\App\Services\EmailTriage\TriageCampaigns::class)->body($doc);
            if (! hash_equals((string) data_get($doc->metadata, 'email_triage.original_hash'), hash('sha256', $original))) throw new \RuntimeException('Original email hash mismatch.');
            return [['text' => $original, 'chunk_id' => $chunks->first()?->id, 'heading' => 'Original email']];
        }
        return $chunks->map(fn ($c) => ['text' => (string) $c->chunk_text, 'chunk_id' => $c->id, 'heading' => is_array($c->heading_path) ? implode(' / ', $c->heading_path) : $c->heading_path])->all();
    }

    public function version(KnowledgeDocument $doc, array $parts): string
    {
        return hash('sha256', json_encode([$doc->version_hash, array_map(fn ($p) => [$p['chunk_id'], hash('sha256', $p['text'])], $parts)], JSON_THROW_ON_ERROR));
    }

    public function sections(array $parts): array
    {
        $size = max(1000, (int) config('knowledge.section_chars', 8000));
        $out = [];
        foreach ($parts as $part) {
            $length = mb_strlen($part['text']);
            for ($offset = 0; $offset < $length; $offset += $size - 300) {
                $text = mb_substr($part['text'], $offset, $size);
                if (trim($text) === '') continue;
                $out[] = ['ordinal' => count($out), 'chunk_id' => $part['chunk_id'], 'heading' => mb_substr((string) $part['heading'], 0, 1000),
                    'offset' => $offset, 'text' => $text, 'text_hash' => hash('sha256', $text)];
                if ($offset + $size >= $length) break;
            }
        }
        return $out;
    }
}
