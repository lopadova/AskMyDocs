<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeObservation extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_observations';

    protected $guarded = ['id'];

    protected $casts = ['event_at' => 'datetime'];
}
