<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.8/W4 — a content-gap rollup row: a question the KB could NOT answer.
 *
 * One row per (tenant, project, normalized query, reason); `occurrences` is
 * incremented every time the same gap recurs. `resolved_at` marks a gap an
 * operator has addressed (excluded from the default ranked list).
 *
 * @property string $tenant_id
 * @property string $project_key
 * @property string $query_hash
 * @property string $normalized_query
 * @property string $query_text
 * @property string $reason
 * @property int $occurrences
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class KbSearchFailure extends Model
{
    use BelongsToTenant;

    // Mirror the refusal reasons KbChatController / MessageController emit.
    public const REASON_NO_CONTEXT = 'no_relevant_context';
    public const REASON_SELF_REFUSAL = 'llm_self_refusal';

    protected $table = 'kb_search_failures';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'query_hash',
        'normalized_query',
        'query_text',
        'reason',
        'occurrences',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'occurrences' => 'int',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
