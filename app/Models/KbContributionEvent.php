<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.15/W1 — an append-only contribution-event row (who did what to the KB).
 *
 * Appended by hooks on the existing ingest / promotion / chat-citation flows via
 * {@see \App\Services\Engagement\ContributionRecorder}. Feeds contributor
 * analytics, "your impact", the digest, and the opt-in gamification layer.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $user_id
 * @property int|null $document_id
 * @property string $project_key
 * @property string $event
 * @property int $weight
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class KbContributionEvent extends Model
{
    use BelongsToTenant;

    public const EVENT_CREATED = 'created';
    public const EVENT_MODIFIED = 'modified';
    public const EVENT_PROMOTED = 'promoted';
    public const EVENT_REVIEWED = 'reviewed';
    public const EVENT_ANSWERED = 'answered';
    public const EVENT_CITED = 'cited';

    /** Default per-event contribution weights (drive the gamification score). */
    public const WEIGHTS = [
        self::EVENT_CREATED => 5,
        self::EVENT_MODIFIED => 2,
        self::EVENT_PROMOTED => 8,
        self::EVENT_REVIEWED => 3,
        self::EVENT_ANSWERED => 1,
        self::EVENT_CITED => 2,
    ];

    protected $table = 'kb_contribution_events';

    /** Only `created_at` is tracked — rows are immutable. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'document_id',
        'project_key',
        'event',
        'weight',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'int',
        'document_id' => 'int',
        'weight' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The canonical event-type list (R18 — derive UI/filters from here, never
     * hard-code a subset).
     *
     * @return list<string>
     */
    public static function eventTypes(): array
    {
        return [
            self::EVENT_CREATED,
            self::EVENT_MODIFIED,
            self::EVENT_PROMOTED,
            self::EVENT_REVIEWED,
            self::EVENT_ANSWERED,
            self::EVENT_CITED,
        ];
    }
}
