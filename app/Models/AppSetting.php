<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.22 (Ciclo 3) — a single runtime-governance override.
 *
 * `(tenant_id, project_key, setting_key)` → `value_json`. `project_key='*'` is
 * the tenant-wide default; any other value is an exact-project override. Layered
 * over the env/config default by {@see \App\Services\Admin\AppSettingsResolver}.
 *
 * R30/R31: tenant-scoped via `BelongsToTenant`.
 */
class AppSetting extends Model
{
    use BelongsToTenant;

    /** Sentinel project_key meaning "every project in this tenant". */
    public const WILDCARD = '*';

    protected $table = 'app_settings';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'setting_key',
        'value_json',
    ];

    protected $casts = [
        'value_json' => 'json',
    ];
}
