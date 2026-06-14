<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.11/P4 — an Auto-Wiki index artifact (per-project roll-up or per-tenant
 * hub). See the `kb_wiki_indices` migration. Rebuildable projection of the
 * markdown corpus; tenant-aware (R30/R31).
 *
 * @property string      $tenant_id
 * @property string      $project_key  '*' for the tenant hub
 * @property string      $index_type   'project' | 'tenant_hub'
 * @property array|null  $payload_json
 */
class KbWikiIndex extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_wiki_indices';

    public const TYPE_PROJECT = 'project';
    public const TYPE_TENANT_HUB = 'tenant_hub';
    public const HUB_PROJECT_KEY = '*';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'index_type',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];
}
