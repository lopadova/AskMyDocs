<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.18/W4 — AI gamification insight snapshot (per tenant / scope / period).
 *
 * Written by {@see \App\Console\Commands\GamificationNarrateCommand}; read by the
 * "My KB" coaching card, the admin engagement health narrative, and the
 * {@see \App\Mcp\Tools\KbGamificationInsightsTool} MCP surface. No LLM call runs
 * in a request hot path — the request reads the persisted row.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $scope_type   user|project|tenant
 * @property string $scope_id     user id (string) | project_key | '' for tenant
 * @property string $period_label e.g. "2026-W25"
 * @property array|null $metrics
 * @property array|null $narrative
 * @property array|null $titles
 * @property string|null $model
 * @property \Illuminate\Support\Carbon|null $computed_at
 * @property int|null $computed_duration_ms
 */
class KbGamificationInsight extends Model
{
    use BelongsToTenant;

    public const SCOPE_USER = 'user';
    public const SCOPE_PROJECT = 'project';
    public const SCOPE_TENANT = 'tenant';

    protected $table = 'kb_gamification_insights';

    /** `computed_at` is the write clock; no created_at/updated_at. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'metrics' => 'array',
        'narrative' => 'array',
        'titles' => 'array',
        'computed_at' => 'datetime',
        'computed_duration_ms' => 'integer',
    ];

    /** Filter to a single scope (user|project|tenant) + its id. */
    public function scopeForScope(Builder $query, string $scopeType, string $scopeId = ''): Builder
    {
        return $query->where('scope_type', $scopeType)->where('scope_id', $scopeId);
    }

    /** Most recent insight for the scoped tenant + scope, or null if none. */
    public function scopeLatestInsight(Builder $query): Builder
    {
        return $query->orderByDesc('period_label')->orderByDesc('computed_at')->limit(1);
    }
}
