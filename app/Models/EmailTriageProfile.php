<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class EmailTriageProfile extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
    protected $casts = ['rules' => 'array', 'enabled' => 'boolean', 'revision' => 'integer'];
}
