<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Padosoft\AskMyDocsMcpPack\Models\McpToolCallAudit as PackageMcpToolCallAudit;

/**
 * v5.0 host audit row, reshaped in v7.0/W1.B to ride on top of the
 * `padosoft/askmydocs-mcp-pack` audit contract.
 *
 * Extends the package model so the orchestrator's
 * {@see \Padosoft\AskMyDocsMcpPack\Services\ToolInvoker} can write
 * through `mcp-pack.audit_model` (set to this class in
 * config/mcp-pack.php) without losing the host-specific columns
 * (`input_json_redacted`, `user_id`, `error_json`, `mcp_servers` FK)
 * AskMyDocs has stored since v5.0.
 *
 * The bridging happens in `creating()`: when the package writes its
 * `actor` shape (string), we hydrate `user_id` if the actor parses
 * as a numeric user id. Pre-existing host code paths that write
 * directly with `user_id` keep working — `actor` is filled with the
 * stringified user id for symmetry.
 */
class McpToolCallAudit extends PackageMcpToolCallAudit
{
    use BelongsToTenant;

    // The package parent declares STATUS_OK / STATUS_ERROR /
    // STATUS_TIMEOUT — same string values. Host preserves its own
    // STATUS_DENIED constant (admin SPA still filters by it).
    public const STATUS_DENIED = 'denied';

    protected $table = 'mcp_tool_call_audit';

    protected $fillable = [
        // package contract columns
        'tenant_id',
        'actor',
        'mcp_server_id',
        'mcp_server_name',
        'conversation_id',
        'message_id',
        'tool_name',
        'input_hash',
        'result_hash',
        'duration_ms',
        'status',
        'error_excerpt',
        // host-legacy columns (kept for operator forensics + admin SPA)
        'user_id',
        'input_json_redacted',
        'error_json',
    ];

    protected $casts = [
        'input_json_redacted' => 'array',
        'error_json' => 'array',
        'duration_ms' => 'int',
    ];

    protected static function booted(): void
    {
        // When the package's ToolInvoker writes through this model,
        // it supplies `actor` (string) but not `user_id`. If the
        // actor parses as a positive integer, mirror it into
        // `user_id` so the existing admin SPA tables — which join
        // through the `user` relationship — continue to render the
        // operator name. The reverse symmetry holds for legacy host
        // writes that arrive with `user_id` but no `actor`.
        static::creating(static function (self $row): void {
            if ($row->user_id === null && is_string($row->actor) && ctype_digit($row->actor)) {
                $userId = (int) $row->actor;
                if ($userId > 0) {
                    $row->user_id = $userId;
                }
            }

            if (($row->actor === null || $row->actor === '') && $row->user_id !== null) {
                $row->actor = (string) $row->user_id;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mcpServer(): BelongsTo
    {
        return $this->belongsTo(McpServer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
