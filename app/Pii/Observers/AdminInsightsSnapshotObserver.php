<?php

declare(strict_types=1);

namespace App\Pii\Observers;

use App\Models\AdminInsightsSnapshot;
use Illuminate\Support\Facades\Log;
use Padosoft\PiiRedactor\RedactorEngine;
use Throwable;

/**
 * v4.3/W1 sub-PR 4.5 — A6 — AdminInsightsSnapshot `creating` observer.
 *
 * The snapshot row carries six JSON columns that surface in the admin
 * `/app/admin/insights` dashboard. v4.1 already redacts the
 * `coverage_gaps` cluster's `sample_questions` BEFORE clustering inside
 * `AiInsightsService::clusterQuestionsViaLlm()` — but other JSON cells
 * (e.g. `orphan_docs[].slug`, `stale_docs[].slug`) can carry document
 * slugs that include person names or identifiers.
 *
 * This observer walks every JSON column and redacts every string value
 * inside the nested structure when both `kb.pii_redactor.enabled` AND
 * `kb.pii_redactor.redact_insights_snippets` (the existing v4.1 knob,
 * extended in scope) are true. The walk is depth-first so nested
 * `sample_questions` arrays inside `coverage_gaps` are covered too —
 * a defence-in-depth backstop for the v4.1 in-service redaction.
 *
 * R14 inversion: redactor failures log + pass through. Insights are
 * a daily computed projection; a partial-row write is fine, a missed
 * snapshot row is not.
 */
final class AdminInsightsSnapshotObserver
{
    /**
     * @var list<string>
     */
    private const PAYLOAD_COLUMNS = [
        'suggest_promotions',
        'orphan_docs',
        'suggested_tags',
        'coverage_gaps',
        'stale_docs',
        'quality_report',
    ];

    public function __construct(private readonly RedactorEngine $engine) {}

    public function creating(AdminInsightsSnapshot $snapshot): void
    {
        if (! $this->shouldRedact()) {
            return;
        }

        try {
            foreach (self::PAYLOAD_COLUMNS as $column) {
                $value = $snapshot->getAttribute($column);
                if (! is_array($value)) {
                    continue;
                }
                $snapshot->setAttribute($column, $this->redactArrayValues($value));
            }
        } catch (Throwable $e) {
            Log::warning('AdminInsightsSnapshotObserver redaction failed; original values kept.', [
                'snapshot_date' => $snapshot->getAttribute('snapshot_date'),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldRedact(): bool
    {
        return (bool) config('kb.pii_redactor.enabled', false)
            && (bool) config('kb.pii_redactor.redact_insights_snippets', false);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function redactArrayValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) && $value !== '') {
                $values[$key] = $this->engine->redact($value);
                continue;
            }
            if (is_array($value)) {
                $values[$key] = $this->redactArrayValues($value);
            }
        }

        return $values;
    }
}
