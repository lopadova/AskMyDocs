<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeSpecification extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_specifications';

    protected $guarded = ['id'];

    protected $casts = ['blocks' => 'array', 'activated_at' => 'datetime'];
}
