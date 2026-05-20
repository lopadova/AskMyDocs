<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class TenantSchedulerOverride extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scheduler_overrides';

    protected $fillable = [
        'tenant_id',
        'slot_name',
        'cron',
        'enabled',
        'timezone',
    ];

    protected $casts = [
        'enabled' => 'bool',
    ];
}

