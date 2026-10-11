<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class EmailTriageCampaignItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
    protected $attributes = ['status' => 'waiting'];
}
