<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class QaCampaign extends Model
{
    use BelongsToTenant;

    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id'];
    protected $casts = ['manifest_json' => 'array', 'budget_nanos' => 'integer',
        'spent_nanos' => 'integer', 'reserved_nanos' => 'integer', 'attempt_count' => 'integer'];
}
