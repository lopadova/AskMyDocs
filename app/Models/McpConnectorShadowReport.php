<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class McpConnectorShadowReport extends Model
{
    use BelongsToTenant;

    public const MATCH = 'match';

    public const DRIFT = 'drift';

    public const ERROR = 'error';

    public const EXPECTED_EXCEPTION = 'expected_exception';

    protected $table = 'mcp_connector_shadow_reports';

    protected $fillable = [
        'tenant_id',
        'mcp_server_id',
        'mcp_connector_connection_id',
        'status',
        'legacy_catalog_hash',
        'connector_catalog_hash',
        'summary_json',
        'blockers_json',
        'warnings_json',
        'compared_at',
    ];

    protected $casts = [
        'summary_json' => 'array',
        'blockers_json' => 'array',
        'warnings_json' => 'array',
        'compared_at' => 'datetime',
    ];
}
