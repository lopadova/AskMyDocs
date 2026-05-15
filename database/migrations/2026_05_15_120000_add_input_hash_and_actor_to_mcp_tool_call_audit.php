<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7.0/W6.2 — additive coexistence migration for the package swap.
 *
 * The host's existing `mcp_tool_call_audit` table predates the
 * `padosoft/askmydocs-mcp-pack` package by a year and uses a richer
 * shape: foreign keys to `users` + `mcp_servers`, `input_json_redacted`
 * for operator forensics, and a strict ENUM `status`. The package's
 * write contract is leaner: SHA-256 hashes (`input_hash`,
 * `result_hash`), a string-form `actor` (decoupled from the host's
 * user table), and a string `status` (so it can emit
 * `transport_error` etc. without an enum migration).
 *
 * This migration adds the two columns the package writer references
 * that don't exist on the host table yet (`input_hash` + `actor`).
 * It is NOT the full bridge — the package writer still can't satisfy
 * the host's NOT NULL `user_id` FK or its strict `status` enum
 * (which has no slot for `transport_error`). The remaining schema
 * work — make `user_id` nullable when `actor` is supplied AND widen
 * `status` from enum to string — lands in W6.3 alongside the inline-
 * delete + adapter port. Splitting it this way keeps the load-bearing
 * column adds + backfill on its own R36 cycle so a backfill bug
 * doesn't drag down the much larger swap PR.
 *
 *   - `input_hash`   — char(64), nullable. SHA-256 of the redacted
 *                      input the package writes; also auto-derived
 *                      from `input_json_redacted` in the host model's
 *                      `creating()` hook so legacy host writes keep
 *                      populating it.
 *   - `actor`        — string(100), nullable. The package's
 *                      tenant-agnostic actor identifier; the host
 *                      can leave it null and lean on `user_id`,
 *                      OR populate it from `user_id` when convenient.
 *
 * Both columns are nullable + indexable so the migration is purely
 * additive: existing rows survive, existing queries keep working,
 * and the package can write rows the moment the audit-model swap
 * lands in W6.3.
 *
 * **Backfill**: every existing row gets its `input_hash` computed
 * by a MIGRATION-LOCAL `canonicalHash()` helper (private method on
 * this anonymous migration class). The algorithm is intentionally
 * duplicated from `\App\Models\McpToolCallAudit::canonicalHash()` so
 * the migration stays runnable years from now even if the host
 * model is renamed, relocated, or has its helper signature changed —
 * historical migrations must never depend on application-layer code.
 *
 * Both helpers recursively `ksort()` associative-array keys before
 * encoding with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
 * JSON_INVALID_UTF8_SUBSTITUTE` so retrospective hash lookups join
 * cleanly against fresh writes regardless of who emitted the original
 * payload (PHP, Python clients, browser clients) or in what key
 * order. They MUST stay in lockstep — a divergence would require a
 * follow-up rehash migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add columns ONLY — defer the `input_hash` index until
        //    after the backfill so each update doesn't pay the
        //    index-maintenance cost. On a 100k-row table the index
        //    cost-per-row dwarfs the column-write cost.
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->char('input_hash', 64)->nullable()->after('tool_name');
            $table->string('actor', 100)->nullable()->after('user_id');
        });

        // 2. Backfill the new `input_hash` column for every pre-
        //    existing row. We chunk by primary key so the migration
        //    scales past a few thousand rows without blowing memory.
        //    SHA-256 is computed in PHP for cross-DB portability —
        //    `sha2()` exists on MySQL only, and Postgres needs
        //    `pgcrypto`.
        //
        //    The canonicalisation algorithm is INLINED below
        //    (`canonicalHash()` private method on this migration
        //    class). Historical migrations must never depend on
        //    application-layer model code — if `App\Models\
        //    McpToolCallAudit` were renamed, moved, or had its
        //    helper signature changed, fresh installs and rollbacks
        //    would break this migration. The host model's
        //    `creating()` hook uses an identical algorithm; both
        //    paths must stay in sync if the canonical form ever
        //    changes (would require a follow-up rehash migration).
        //
        //    Each chunk issues ONE bulk update via a CASE WHEN
        //    expression instead of N per-row UPDATEs. On a 100k-row
        //    table that turns ~100,000 per-row statements (one per
        //    audit row) into 100,000 / 250 ≈ 400 bulk statements
        //    (one per chunk) — orders of magnitude less lock
        //    contention on the table.
        //
        //    Chunk size 250: each row contributes 2 bound parameters
        //    (`WHEN ? THEN ?`), so 250 rows = 500 bindings. That
        //    stays well clear of the SQLite default
        //    `SQLITE_LIMIT_VARIABLE_NUMBER = 999` on older builds —
        //    and is still bounded enough for Postgres / MySQL to
        //    process in a single quick statement.
        // Narrow the SELECT to just the columns the backfill needs.
        // `mcp_tool_call_audit` carries `error_json`, two
        // `timestamps`, etc.; pulling all of them for every chunk
        // would inflate the migration's IO + memory footprint on
        // large tables. `chunkById` requires the ordering column
        // (`id`) be in the projection.
        DB::table('mcp_tool_call_audit')
            ->select(['id', 'input_json_redacted'])
            ->whereNull('input_hash')
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                $hashes = [];
                foreach ($rows as $row) {
                    $hashes[(int) $row->id] = $this->canonicalHash(
                        $row->input_json_redacted ?? '',
                    );
                }
                if ($hashes === []) {
                    return;
                }
                $this->bulkUpdateInputHashes($hashes);
            });

        // 3. NOW create the index — hash-lookup queries
        //    ("find every call whose input matched <known hash>")
        //    use it; nullable column, regular index is fine.
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->index('input_hash', 'idx_mcp_tool_call_audit_input_hash');
        });
    }

    /**
     * Migration-LOCAL canonical SHA-256 of a redacted-input payload.
     * Intentionally duplicates the host model's
     * `\App\Models\McpToolCallAudit::canonicalHash()` so this
     * migration NEVER depends on application-layer code that could
     * be renamed, refactored, or relocated between releases. Fresh
     * installs and rollbacks must keep working three years from now.
     *
     * Algorithm (must stay in lockstep with the host model):
     *
     *   1. Decode if the driver handed us back the raw JSON string;
     *      malformed JSON falls through as the literal bytes.
     *   2. Recursively `ksort()` associative-array keys; list arrays
     *      keep positional order.
     *   3. Re-encode with `JSON_UNESCAPED_UNICODE |
     *      JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE`
     *      so invalid UTF-8 lands as U+FFFD instead of breaking the
     *      encoder.
     *   4. On hard encode failure (circular refs etc.), fall back to
     *      a deterministic marker embedding the json error code so
     *      distinct failure modes never collide.
     *
     * @param  array<mixed>|string  $payload
     */
    private function canonicalHash(array|string $payload): string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = $decoded === null && json_last_error() !== JSON_ERROR_NONE
                ? $payload
                : $decoded;
        }
        if (is_array($payload)) {
            $this->recursivelySortKeys($payload);
        }
        if (is_string($payload)) {
            return hash('sha256', $payload);
        }
        $canonical = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($canonical === false) {
            $canonical = '__canonical_hash_encode_failed__:' . json_last_error();
        }
        return hash('sha256', $canonical);
    }

    /** @param  array<mixed>  $payload */
    private function recursivelySortKeys(array &$payload): void
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->recursivelySortKeys($value);
            }
        }
        unset($value);
    }

    /**
     * Issue a single SQL UPDATE that sets `input_hash` per id using a
     * CASE WHEN expression — portable across SQLite / Postgres /
     * MySQL without needing per-driver upsert quirks. Hashes are
     * fixed-length hex literals so direct string concatenation is
     * safe; ids come from a primary-key scan so quoting them is
     * trivial. We still parameterise to stay defensive.
     *
     * @param  array<int,string>  $hashes  map of `id => hex_hash`
     */
    private function bulkUpdateInputHashes(array $hashes): void
    {
        $cases = [];
        $bindings = [];
        $ids = [];
        foreach ($hashes as $id => $hash) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $hash;
            $ids[] = $id;
        }
        $caseSql = implode(' ', $cases);
        $idList = implode(',', array_map('intval', $ids));
        // The `AND input_hash IS NULL` guard makes the bulk update
        // safe to run against a live host: between the `chunkById`
        // SELECT (rows with NULL input_hash) and this UPDATE, a
        // concurrent writer COULD set `input_hash` on one of the
        // selected ids (e.g. an MCP tool call landed during deploy).
        // Without the guard we'd clobber the fresh hash with our
        // backfilled value. The CASE expression still computes per-
        // row but the WHERE prevents the write.
        $sql = "UPDATE mcp_tool_call_audit
                SET input_hash = CASE id {$caseSql} END
                WHERE id IN ({$idList})
                  AND input_hash IS NULL";
        DB::update($sql, $bindings);
    }

    public function down(): void
    {
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->dropIndex('idx_mcp_tool_call_audit_input_hash');
            $table->dropColumn(['input_hash', 'actor']);
        });
    }
};
