<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One setup per project. The project owns the tenant scope. */
final class WidgetProjectSetup extends Model
{
    protected $primaryKey = 'project_id';

    public $incrementing = false;

    protected $fillable = ['project_id', 'widget_key_id', 'user_auth_enabled', 'language', 'audience', 'topics'];

    protected $casts = ['user_auth_enabled' => 'boolean'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
