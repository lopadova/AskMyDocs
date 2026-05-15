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
 * by `\App\Models\McpToolCallAudit::canonicalHash()` — the same
 * helper the model's `creating()` hook uses. The helper recursively
 * `ksort()`s associative-array keys before encoding with
 * `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
 * JSON_INVALID_UTF8_SUBSTITUTE` so retrospective hash lookups join
 * cleanly against fresh writes regardless of who emitted the original
 * payload (PHP, Python clients, browser clients) or in what key
 * order. Insertion-order-dependent hashing would have made the
 * cross-writer coexistence story unworkable.
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
        //    Delegate the canonicalisation to
        //    `\App\Models\McpToolCallAudit::canonicalHash()` so the
        //    backfill and the model `creating()` hook produce
        //    identical hashes for the same logical payload —
        //    including the recursive `ksort()` that makes the hash
        //    key-order-independent. Without this, rows seeded by
        //    Python or browser-side clients (with arbitrary key
        //    ordering) would never join hashes with rows written
        //    later by the host hook.
        //
        //    Each chunk issues ONE bulk update via a CASE WHEN
        //    expression instead of N per-row UPDATEs. On a 100k-row
        //    table this collapses 200 round-trips into 200/$chunk =
        //    400 individual SQL statements down to one statement per
        //    chunk — orders of magnitude less lock contention.
        //
        //    Chunk size 250: each row contributes 2 bound parameters
        //    (`WHEN ? THEN ?`), so 250 rows = 500 bindings. That
        //    stays well clear of the SQLite default
        //    `SQLITE_LIMIT_VARIABLE_NUMBER = 999` on older builds —
        //    and is still bounded enough for Postgres / MySQL to
        //    process in a single quick statement.
        DB::table('mcp_tool_call_audit')
            ->whereNull('input_hash')
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                $hashes = [];
                foreach ($rows as $row) {
                    $hashes[(int) $row->id] = \App\Models\McpToolCallAudit::canonicalHash(
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
