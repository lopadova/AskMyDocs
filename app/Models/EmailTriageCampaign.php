<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class EmailTriageCampaign extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
    protected $attributes = ['status' => 'running', 'cursor' => 0, 'selected' => 0, 'unavailable' => 0];
    protected $casts = ['filters' => 'array'];
}
