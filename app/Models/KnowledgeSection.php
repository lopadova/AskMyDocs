<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeSection extends Model
{
    protected $table = 'knowledge_sections';

    protected $guarded = ['id'];

    protected $casts = ['result' => 'array', 'decisions' => 'array', 'usage' => 'array'];
}
