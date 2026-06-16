<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.15/W3 — a user's rich-digest preferences: cadence + enabled sections.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $frequency
 * @property array|null $sections
 */
class DigestPreference extends Model
{
    use BelongsToTenant;

    public const FREQ_WEEKLY = 'weekly';
    public const FREQ_MONTHLY = 'monthly';
    public const FREQ_OFF = 'off';

    /** All section keys a user can toggle (R18 — derive UI from here). */
    public const SECTIONS = ['metrics', 'new_docs', 'stale_docs', 'top_gaps', 'leaderboard'];

    protected $table = 'digest_preferences';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'frequency',
        'sections',
    ];

    protected $casts = [
        'user_id' => 'int',
        'sections' => 'array',
    ];

    /**
     * @return list<string>
     */
    public static function frequencies(): array
    {
        return [self::FREQ_WEEKLY, self::FREQ_MONTHLY, self::FREQ_OFF];
    }
}
