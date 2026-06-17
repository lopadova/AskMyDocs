<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.15/W5 — an awarded gamification badge (opt-in).
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $user_id
 * @property string $badge_key
 * @property \Illuminate\Support\Carbon|null $awarded_at
 */
class KbUserBadge extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_user_badges';

    /** Only `awarded_at` is tracked; no created_at/updated_at columns. */
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'badge_key',
        'awarded_at',
    ];

    protected $casts = [
        'user_id' => 'int',
        'awarded_at' => 'datetime',
    ];
}
