<?php

declare(strict_types=1);

namespace App\Services\Digest\Renderers;

use App\Services\Digest\DigestPayload;

/**
 * v8.15/W2 — shared text composition for the channel card renderers so the
 * Discord/Slack/Teams bodies never drift. Subclasses only map these strings
 * into their channel's JSON envelope.
 */
abstract class AbstractDigestCardRenderer implements DigestCardRendererInterface
{
    protected const BRAND_COLOR_HEX = '#6F42C1';
    protected const BRAND_COLOR_INT = 0x6F42C1;

    protected function title(DigestPayload $payload): string
    {
        return '📊 AskMyDocs — '.ucfirst($payload->frequency).' KB digest';
    }

    /** The narrative if present, else a deterministic one-liner. */
    protected function summary(DigestPayload $payload): string
    {
        if ($payload->narrative !== null && $payload->narrative !== '') {
            return $payload->narrative;
        }

        if ($payload->isQuiet()) {
            return sprintf(
                'A quiet %s — no new documents, no stale reviews, and no unanswered questions. Ask your KB anything to keep it growing.',
                $payload->frequency === 'monthly' ? 'month' : 'week',
            );
        }

        $m = $payload->metrics;

        return sprintf(
            '%d contribution(s) from %d contributor(s): %d new, %d modified, %d promoted. %d question(s) answered, %d open gap(s).',
            (int) ($m['new_docs'] ?? 0) + (int) ($m['modified_docs'] ?? 0) + (int) ($m['promoted_docs'] ?? 0),
            (int) ($m['contributors'] ?? 0),
            (int) ($m['new_docs'] ?? 0),
            (int) ($m['modified_docs'] ?? 0),
            (int) ($m['promoted_docs'] ?? 0),
            (int) ($m['answers'] ?? 0),
            (int) ($m['open_gaps'] ?? 0),
        );
    }

    /** Compact KPI line, e.g. "Coverage 62% · Answer rate 84% · Avg debt 41". */
    protected function metricsLine(DigestPayload $payload): string
    {
        $m = $payload->metrics;
        $coverage = $m['canonical_coverage_pct'] ?? null;
        $parts = [
            'Coverage '.($coverage === null ? '—' : $this->num($coverage).'%'),
            sprintf('Answer rate %s', $this->pct($m['answer_rate'] ?? null)),
            sprintf('Avg debt %s', $this->num($m['avg_debt_score'] ?? null)),
            sprintf('Stale %d', (int) ($m['stale_count'] ?? 0)),
        ];

        return implode(' · ', $parts);
    }

    /**
     * @return list<string>
     */
    protected function topGapLines(DigestPayload $payload, int $limit = 5): array
    {
        $lines = [];
        foreach (array_slice($payload->topGaps, 0, $limit) as $gap) {
            $lines[] = sprintf('• %s (%d×)', $gap['question'], $gap['occurrences']);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function staleLines(DigestPayload $payload, int $limit = 5): array
    {
        $lines = [];
        foreach (array_slice($payload->staleDocs, 0, $limit) as $doc) {
            $lines[] = sprintf('• %s (debt %d, %dd untouched)', $doc['title'], $doc['debt_score'], $doc['age_days']);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function newDocLines(DigestPayload $payload, int $limit = 5): array
    {
        $lines = [];
        foreach (array_slice($payload->newDocs, 0, $limit) as $doc) {
            $lines[] = sprintf('• %s (%s)', $doc['title'], $doc['change']);
        }

        return $lines;
    }

    private function num(mixed $v): string
    {
        return $v === null ? '—' : (string) (is_float($v) ? round($v, 1) : $v);
    }

    private function pct(mixed $v): string
    {
        return $v === null ? '—' : round(((float) $v) * 100).'%';
    }
}
