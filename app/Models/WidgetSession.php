<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WidgetSession — una sessione conversazionale KITT (vedi migration).
 *
 * Esposta a FE via `public_session_id` (UUID opaco): è la route key, così
 * gli endpoint /api/widget/sessions/{session} non espongono l'auto-increment.
 *
 * R31: BelongsToTenant + tenant_id in $fillable.
 */
class WidgetSession extends Model
{
    use BelongsToTenant;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_WAITING_USER = 'waiting_user';
    public const STATUS_WAITING_TOOL = 'waiting_tool';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'widget_key_id',
        'project_key',
        'public_session_id',
        'status',
        'skill',
        'mission',
        'page_url',
        'origin',
        'summary',
        'blocked_reason',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /** Risolve la sessione via UUID pubblico nelle route, non via id. */
    public function getRouteKeyName(): string
    {
        return 'public_session_id';
    }

    /** @return BelongsTo<WidgetKey, WidgetSession> */
    public function widgetKey(): BelongsTo
    {
        return $this->belongsTo(WidgetKey::class);
    }

    /** @return HasMany<WidgetSessionStep> */
    public function steps(): HasMany
    {
        return $this->hasMany(WidgetSessionStep::class);
    }
}
