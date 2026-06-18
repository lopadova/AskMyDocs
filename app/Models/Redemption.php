<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redemption — the immutable claim event (docs/04-data-model.md).
 *
 * Append-only: rows are inserted by the atomic claim and thereafter only ever
 * have PII columns anonymized in place (erasure), never logically mutated.
 * `UNIQUE(code_id, redeemer_id)` is the idempotency anchor of the system.
 *
 * Only `redeemed_at` is managed — there is no `created_at`/`updated_at`
 * (Eloquent timestamps disabled).
 */
class Redemption extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public $timestamps = false;

    protected $table = 'invite_redemptions';

    protected $fillable = [
        'tenant_id',
        'code_id',
        'redeemer_id',
        'invitation_id',
        'redeemed_at',
        'ip',
        'user_agent',
        'fingerprint',
        'context',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'context' => 'array',
    ];

    protected $hidden = [
        // PII / PII-adjacent — never serialize to API responses.
        'ip',
        'user_agent',
        'fingerprint',
    ];

    public function code(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class, 'code_id');
    }

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemer_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class, 'invitation_id');
    }
}
