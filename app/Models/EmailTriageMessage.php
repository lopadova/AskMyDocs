<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class EmailTriageMessage extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
    protected $attributes = ['status' => 'awaiting_files'];
    protected $casts = ['metadata' => 'array', 'manifest' => 'array', 'email_date' => 'datetime'];
}
