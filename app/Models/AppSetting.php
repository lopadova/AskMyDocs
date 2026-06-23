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

    /**
     * Normalise a caller-supplied project scope: an absent (null) or empty
     * string means "tenant-wide" → the wildcard. A real project key (including
     * the string '0') is returned unchanged — never treat '0' as empty.
     */
    public static function normalizeProjectKey(mixed $value): string
    {
        // Non-scalar (e.g. an array from project_key[]=) is never a valid
        // scope → wildcard, rather than the "Array" string an unguarded cast
        // would produce.
        if (! is_scalar($value)) {
            return self::WILDCARD;
        }

        $value = trim((string) $value);

        // Empty / whitespace-only → tenant-wide; a real key (including '0') is
        // returned unchanged.
        return $value === '' ? self::WILDCARD : $value;
    }
}
