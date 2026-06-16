<?php

declare(strict_types=1);

namespace App\Services\Digest;

/**
 * v8.15/W2 — the typed, channel-agnostic digest content.
 *
 * {@see DigestComposer} builds one per tenant (and, in W4, per user) from the
 * engagement snapshot + recent contribution events + stale docs + content gaps.
 * Renderers ({@see Renderers\DigestCardRendererInterface}, the email Mailable)
 * turn it into a channel-specific payload. The AI narrator may fill `narrative`.
 *
 * Pure data — JSON-encodable, no Eloquent models — so it survives queue
 * serialisation and `--preview` JSON dumps.
 */
final class DigestPayload
{
    /**
     * @param  array<string, mixed>  $metrics           engagement snapshot metrics
     * @param  list<array{title:string, project_key:string, slug:?string, change:string}>  $newDocs
     * @param  list<array{title:string, project_key:string, slug:?string, age_days:int, debt_score:int}>  $staleDocs
     * @param  list<array{question:string, occurrences:int, project_key:string}>  $topGaps
     * @param  list<array{user_id:int, name:string, score:int, events:int}>  $leaderboard
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $frequency,        // 'weekly' | 'monthly'
        public readonly string $periodStart,      // Y-m-d
        public readonly string $periodEnd,        // Y-m-d
        public readonly array $metrics,
        public readonly array $newDocs,
        public readonly array $staleDocs,
        public readonly array $topGaps,
        public readonly array $leaderboard,
        public ?string $narrative = null,
    ) {
    }

    /** Period label e.g. "weekly digest · 2026-06-09 → 2026-06-16". */
    public function periodLabel(): string
    {
        return "{$this->frequency} digest · {$this->periodStart} → {$this->periodEnd}";
    }

    /** A digest is "quiet" when nothing happened in the window worth surfacing. */
    public function isQuiet(): bool
    {
        return $this->newDocs === []
            && $this->staleDocs === []
            && $this->topGaps === []
            && (int) ($this->metrics['answers'] ?? 0) === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'frequency' => $this->frequency,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'metrics' => $this->metrics,
            'new_docs' => $this->newDocs,
            'stale_docs' => $this->staleDocs,
            'top_gaps' => $this->topGaps,
            'leaderboard' => $this->leaderboard,
            'narrative' => $this->narrative,
            'quiet' => $this->isQuiet(),
        ];
    }
}
