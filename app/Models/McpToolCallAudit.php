<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * v5.0/W7 — audit row for every MCP tool call.
 *
 * v7.0/W6.2 — partial coexistence shape. The host's columns
 * (`user_id`, `input_json_redacted`, `error_json`, enum-style
 * `status`) stay authoritative for operator forensics. The two
 * new columns `input_hash` + `actor` are added so the
 * `padosoft/askmydocs-mcp-pack` package can populate them when the
 * audit-model cutover lands in W6.3. **W6.2 does NOT make the host
 * table fully package-writable yet**: `user_id` is still a NOT
 * NULL FK and `status` is still an enum that cannot hold
 * `transport_error`. W6.3 widens both alongside the inline-delete
 * + adapter port, by which point the host model will be a true
 * dual-shape adapter.
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
        if (is_string($payload)) {
            return hash('sha256', $payload);
        }
        // `JSON_INVALID_UTF8_SUBSTITUTE` keeps `json_encode()` from
        // returning `false` on payloads with invalid UTF-8 byte
        // sequences (legacy ingest, raw-binary inputs). Without it
        // a casting-to-string would turn `false` into `""` and every
        // such payload would collide on `sha256('')`. The substitute
        // codepoint (U+FFFD) is deterministic so the hash stays
        // stable across re-encodes of the same logical payload.
        $canonical = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($canonical === false) {
            // Hard failure (e.g. circular references). Fall back to a
            // deterministic non-empty representation that includes the
            // last json_encode error code so distinct failure modes do
            // not collide.
            $canonical = '__canonical_hash_encode_failed__:' . json_last_error();
        }
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
        // `foreach ... as &$value` leaves $value pointing at the last
        // element after the loop ends; subsequent assignments to
        // $value elsewhere would mutate the array. Always unset.
        unset($value);
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
