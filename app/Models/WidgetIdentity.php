<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WidgetIdentity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'widget_key_id',
        'project_key',
        'subject_hash',
        'last_seen_at',
    ];

    protected $hidden = ['subject_hash'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function widgetKey(): BelongsTo
    {
        return $this->belongsTo(WidgetKey::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WidgetSession::class);
    }
}
