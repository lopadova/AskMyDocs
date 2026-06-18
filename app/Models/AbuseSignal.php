<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AbuseSignal — append-only risk observation (docs/10-anti-abuse.md).
 *
 * `subject_value` is PII when the subject is ip/email/fingerprint and is
 * stored hashed/truncated. `context` carries the decision but no raw PII.
 * Immutable: insert-only, only `created_at` is managed.
 */
class AbuseSignal extends Model
{
    use BelongsToTenant;
    use HasFactory;

    public const SUBJECT_IP = 'ip';
    public const SUBJECT_EMAIL = 'email';
    public const SUBJECT_ACCOUNT = 'account';
    public const SUBJECT_FINGERPRINT = 'fingerprint';
    public const SUBJECT_CODE = 'code';

    public const TYPE_RATE_LIMIT = 'rate_limit';
    public const TYPE_SELF_REFERRAL = 'self_referral';
    public const TYPE_DISPOSABLE_EMAIL = 'disposable_email';
    public const TYPE_VELOCITY = 'velocity';
    public const TYPE_BLACKLIST = 'blacklist';
    public const TYPE_HONEYPOT = 'honeypot';
    public const TYPE_FINGERPRINT_COLLISION = 'fingerprint_collision';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARN = 'warn';
    public const SEVERITY_BLOCK = 'block';

    public const ACTION_NONE = 'none';
    public const ACTION_FLAG = 'flag';
    public const ACTION_THROTTLE = 'throttle';
    public const ACTION_BLOCK = 'block';

    public const UPDATED_AT = null;

    protected $table = 'invite_abuse_signals';

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_value',
        'signal_type',
        'severity',
        'score',
        'action_taken',
        'context',
        'created_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    protected $hidden = [
        // subject_value can carry a hashed identifier — keep it out of any
        // accidental API serialization; admin queries select it explicitly.
        'subject_value',
    ];
}
