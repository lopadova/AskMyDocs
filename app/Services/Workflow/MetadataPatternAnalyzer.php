<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Collection;

/**
 * v4.7/W2 — Frontmatter pattern extractor.
 *
 * Inputs a collection of {@see KnowledgeDocument} rows and returns a
 * compact signature the {@see WorkflowSuggester} feeds to the LLM. Re-
 * uses the rich frontmatter captured by v4.5/W5.5 source-aware
 * ingestion (`frontmatter_json` column) — no additional ingestion work.
 */
final class MetadataPatternAnalyzer
{
    private const TOP_K = 10;

    /**
     * @return array{
     *     canonical_types: array<string, int>,
     *     source_types: array<string, int>,
     *     tags_top_k: array<string, int>,
     *     custom_keys_recurring: array<string, int>,
     *     practice_signals: array<string, int>,
     *     documents_analysed: int,
     * }
     */
    public function analyze(Collection $documents): array
    {
        $canonicalTypes = [];
        $sourceTypes = [];
        $tags = [];
        $customKeys = [];
        $practiceSignals = [];

        foreach ($documents as $doc) {
            $type = $this->stringAttr($doc, 'canonical_type');
            if ($type !== null) {
                $canonicalTypes[$type] = ($canonicalTypes[$type] ?? 0) + 1;
            }

            $sourceType = $this->stringAttr($doc, 'source_type');
            if ($sourceType !== null) {
                $sourceTypes[$sourceType] = ($sourceTypes[$sourceType] ?? 0) + 1;
            }

            $frontmatter = $this->frontmatterArray($doc);
            if ($frontmatter === []) {
                continue;
            }

            foreach (array_keys($frontmatter) as $key) {
                if ($key === '' || $key === '_derived') {
                    continue;
                }
                $customKeys[$key] = ($customKeys[$key] ?? 0) + 1;
            }

            $rawTags = $frontmatter['tags'] ?? null;
            if (is_array($rawTags)) {
                foreach ($rawTags as $tag) {
                    if (! is_string($tag) || $tag === '') {
                        continue;
                    }
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            }

            $signal = $this->practiceSignal($frontmatter);
            if ($signal !== null) {
                $practiceSignals[$signal] = ($practiceSignals[$signal] ?? 0) + 1;
            }
        }

        arsort($canonicalTypes);
        arsort($sourceTypes);
        arsort($tags);
        arsort($customKeys);
        arsort($practiceSignals);

        return [
            'canonical_types' => $canonicalTypes,
            'source_types' => $sourceTypes,
            'tags_top_k' => array_slice($tags, 0, self::TOP_K, true),
            'custom_keys_recurring' => array_slice($customKeys, 0, self::TOP_K, true),
            'practice_signals' => $practiceSignals,
            'documents_analysed' => $documents->count(),
        ];
    }

    private function stringAttr(KnowledgeDocument $doc, string $attr): ?string
    {
        $value = $doc->getAttribute($attr);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function frontmatterArray(KnowledgeDocument $doc): array
    {
        $raw = $doc->getAttribute('frontmatter_json');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function practiceSignal(array $frontmatter): ?string
    {
        $haystack = mb_strtolower(json_encode($frontmatter, JSON_UNESCAPED_UNICODE) ?: '');

        $rules = [
            'legal' => ['legal', 'contract', 'covenant', 'nda', 'agreement'],
            'compliance' => ['gdpr', 'compliance', 'audit', 'iso-27001', 'soc2', 'ai-act'],
            'engineering' => ['runbook', 'adr', 'decision', 'incident', 'postmortem', 'engineering'],
            'sales' => ['sales', 'lead', 'pipeline', 'crm'],
            'support' => ['support', 'ticket', 'customer', 'faq'],
        ];

        foreach ($rules as $practice => $needles) {
            foreach ($needles as $needle) {
                if (mb_strpos($haystack, $needle) !== false) {
                    return $practice;
                }
            }
        }

        return null;
    }
}
