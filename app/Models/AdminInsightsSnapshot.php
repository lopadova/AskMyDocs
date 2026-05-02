<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase I — daily-computed AI insights snapshot.
 *
 * The row is written by {@see \App\Console\Commands\InsightsComputeCommand}
 * once per calendar day at 05:00 and serves every /app/admin/insights
 * request thereafter. The SPA path is read-only against this row — no
 * LLM calls ever enter a user's hot path.
 *
 * Partial-failure contract: any of the six JSON payload columns may be
 * null if the corresponding `AiInsightsService::*` function threw
 * during computation (LLM timeout, quota exhausted, provider outage).
 * The command caught the exception at the function boundary and moved
 * on — the row still exists and the remaining cells are populated.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $snapshot_date
 * @property array|null $suggest_promotions  [{document_id, slug, reason, score}, ...]
 * @property array|null $orphan_docs         [{document_id, slug, last_used_at, chunks_count}, ...]
 * @property array|null $suggested_tags      [{document_id, slug, tags_proposed}, ...]
 * @property array|null $coverage_gaps       [{topic, zero_citation_count, low_confidence_count, sample_questions}, ...]
 * @property array|null $stale_docs          [{document_id, slug, indexed_at, negative_rating_ratio}, ...]
 * @property array|null $quality_report      {chunk_length_distribution, outlier_short, outlier_long, missing_frontmatter}
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property int|null $computed_duration_ms
 */
class AdminInsightsSnapshot extends Model
{
    use BelongsToTenant;

    protected $table = 'admin_insights_snapshots';

    /**
     * No `created_at` / `updated_at` columns on the table — the
     * `computed_at` timestamp is the canonical write clock.
     */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_date' => 'date',
        'suggest_promotions' => 'array',
        'orphan_docs' => 'array',
        'suggested_tags' => 'array',
        'coverage_gaps' => 'array',
        'stale_docs' => 'array',
        'quality_report' => 'array',
        'computed_at' => 'datetime',
        'computed_duration_ms' => 'integer',
    ];

    /**
     * Return the most recent snapshot row, or null if none has been
     * computed yet. Matches the read path used by the SPA's
     * `/api/admin/insights/latest` endpoint.
     */
    public function scopeLatestSnapshot(Builder $query): Builder
    {
        return $query->orderByDesc('snapshot_date')->limit(1);
    }
}
