<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class KnowledgeExtraction extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_extractions';

    protected $guarded = ['id'];

    protected $casts = ['contract' => 'array', 'total_sections' => 'integer', 'processed_sections' => 'integer', 'abstained_sections' => 'integer'];
}
