<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.0/W1.1 — Weekly aggregated digest row (ADR 0012).
 *
 * One row per (tenant, week_start_date). The dispatcher upserts
 * incrementally; the future weekly-digest job (planned cycle slot
 * `notifications:digest-weekly`, lands in W2 alongside the channel
 * adapters) renders the digest from the aggregated payload, ships
 * it via the email channel, and stamps `sent_at` + `recipients_count`
 * post-delivery. Until that job exists, rows here are write-only.
 */
class NotificationDigest extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_digests';

    protected $fillable = [
        'tenant_id',
        'week_start_date',
        'payload',
        'sent_at',
        'recipients_count',
    ];

    protected $casts = [
        'payload' => 'array',
        'week_start_date' => 'date',
        'sent_at' => 'datetime',
        'recipients_count' => 'int',
    ];
}
