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
 *     directly. They MUST still pass `input_json_redacted` (the
 *     column is NOT NULL — passing `[]` is the conventional empty
 *     payload). The hook does NOT default the column; it only
 *     derives `input_hash` when the caller leaves it empty.
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
            $audit->input_hash = self::canonicalHash($payload);
        });
    }

    /**
     * Canonical SHA-256 of a redacted-input payload. Mirrors the
     * migration backfill so cross-writer lookups by hash join
     * cleanly even when the original writer emitted keys in a
     * different order (Python clients, browser-side clients, etc.).
     *
     *   1. Decode if the driver handed us back the raw JSON string.
     *   2. Recursively sort associative-array keys so two payloads
     *      that differ ONLY in insertion order produce the same
     *      hash. List indices stay positional — they're meaningful.
     *   3. Re-encode with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
     *      so the byte representation is identical regardless of
     *      who serialised it.
     *
     * @param  array<mixed>|string  $payload
     */
    public static function canonicalHash(array|string $payload): string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            // Malformed JSON: hash the literal bytes so a bad row
            // is still queryable by its stored form.
            $payload = $decoded === null && json_last_error() !== JSON_ERROR_NONE
                ? $payload
                : $decoded;
        }
        if (is_array($payload)) {
            self::recursivelySortKeys($payload);
        }
        $canonical = is_string($payload)
            ? $payload
            : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $canonical);
    }

    /**
     * Sort assoc-array keys in-place, recursively. List arrays
     * (numeric, zero-indexed, contiguous) keep their positional
     * order — reordering would break payloads where position is
     * meaningful (e.g. function-argument lists).
     *
     * @param  array<mixed>  $payload
     */
    private static function recursivelySortKeys(array &$payload): void
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as &$value) {
            if (is_array($value)) {
                self::recursivelySortKeys($value);
            }
        }
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
