<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MCP server registry row (v5.0/W1 scaffold).
 *
 * One row represents one registered external MCP server for a tenant.
 * `auth_config_encrypted` is encrypted by controller/service boundary
 * before persistence (R21). `enabled_tools_json` can be null until
 * handshake runs.
 */
class McpServer extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ERRORED = 'errored';

    public const TRANSPORT_STDIO = 'stdio';
    public const TRANSPORT_SSE = 'sse';
    public const TRANSPORT_HTTP = 'http';

    protected $table = 'mcp_servers';

    protected $fillable = [
        'tenant_id',
        'name',
        'transport',
        'endpoint',
        'auth_config_encrypted',
        'enabled_tools_json',
        'status',
        'last_handshake_at',
        'handshake_response_json',
        'created_by',
    ];

    protected $casts = [
        'enabled_tools_json' => 'array',
        'handshake_response_json' => 'array',
        'last_handshake_at' => 'datetime',
    ];

    protected $hidden = [
        'auth_config_encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toolCallAudits(): HasMany
    {
        return $this->hasMany(McpToolCallAudit::class);
    }

}
