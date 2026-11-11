<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeSpecificationScope extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_specification_scopes';

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean'];
}
