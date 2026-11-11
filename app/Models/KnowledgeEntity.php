<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeEntity extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_entities';

    protected $guarded = ['id'];

    protected $casts = [];
}
