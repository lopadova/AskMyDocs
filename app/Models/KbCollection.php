<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class KbCollection extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_collections';

    protected $fillable = [
        'tenant_id',
        'slug',
        'name',
        'description',
        'visibility',
        'criteria',
        'semantic_prompt',
        'semantic_prompt_embedding',
        'threshold',
    ];

    protected $casts = [
        'criteria' => 'array',
        'semantic_prompt_embedding' => 'array',
        'threshold' => 'float',
    ];
}

