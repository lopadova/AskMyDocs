<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * v5.0/W7 — audit row for every MCP tool call.
 *
 * v7.0/W6.2 — coexistence shape. The host's columns
 * (`user_id`, `input_json_redacted`, `error_json`, enum-style
 * `status`) stay authoritative for operator forensics. The two
 * additive columns `input_hash` + `actor` exist so the
 * `padosoft/askmydocs-mcp-pack` package can write rows directly
 * (post-W6.3 cutover) without losing the host's richer payload.
 *
 * Convention going forward:
 *   - Host writes (legacy code path) fill `input_json_redacted` +
 *     `user_id`; the `creating()` hook below derives `input_hash`
 *     from the redacted payload automatically.
 *   - Package writes (post-cutover) fill `input_hash` + `actor`
 *     directly; the hook leaves `input_json_redacted` as an empty
 *     `[]` JSON when none was provided.
 */
class McpToolCallAudit extends Model
{
    use BelongsToTenant;

    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_DENIED = 'denied';

    protected $table = 'mcp_tool_call_audit';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'actor',
        'mcp_server_id',
        'conversation_id',
        'message_id',
        'tool_name',
        'input_hash',
        'input_json_redacted',
        'result_hash',
        'duration_ms',
        'status',
        'error_json',
    ];

    protected $casts = [
        'input_json_redacted' => 'array',
        'error_json' => 'array',
        'duration_ms' => 'int',
    ];

    protected static function booted(): void
    {
        // Auto-derive `input_hash` from `input_json_redacted` for
        // legacy host writes so the column is always populated going
        // forward, without forcing every caller to compute the hash
        // themselves. Package writes that already set `input_hash`
        // explicitly are NOT overwritten.
        static::creating(static function (self $audit): void {
            if (! empty($audit->input_hash)) {
                return;
            }
            $payload = $audit->input_json_redacted;
            if ($payload === null) {
                return;
            }
            $canonical = is_string($payload)
                ? $payload
                : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $audit->input_hash = hash('sha256', $canonical);
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
