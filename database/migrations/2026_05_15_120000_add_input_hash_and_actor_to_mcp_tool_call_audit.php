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
 * This migration adds the package's two missing columns to the
 * existing host table so the audit-model subclass (W6.3) can satisfy
 * BOTH write contracts from one row:
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
 * **Backfill**: every existing row gets `input_hash =
 * sha256(json_encode(input_json_redacted))` so retrospective queries
 * by hash join cleanly against new rows written by the package.
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
        //    The hash MUST match what the host model's `creating()`
        //    hook produces for the same payload, otherwise rows
        //    backfilled here would never match against rows written
        //    later by the package (or by host code post-hook). The
        //    model hook canonicalises the payload via
        //    `json_encode($payload, JSON_UNESCAPED_UNICODE |
        //    JSON_UNESCAPED_SLASHES)`. We mirror that exactly: when
        //    the driver hands us back a string (Postgres / MySQL),
        //    `json_decode()` it first and re-encode with the same
        //    flags so escaping / key ordering is identical.
        DB::table('mcp_tool_call_audit')
            ->whereNull('input_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = $row->input_json_redacted ?? '';
                    if (is_string($payload)) {
                        $decoded = json_decode($payload, true);
                        // Malformed JSON is treated as the literal
                        // string — preserving the original stored
                        // bytes is safer than skipping the row.
                        $payload = $decoded === null && json_last_error() !== JSON_ERROR_NONE
                            ? $payload
                            : $decoded;
                    }
                    $canonical = is_string($payload)
                        ? $payload
                        : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    DB::table('mcp_tool_call_audit')
                        ->where('id', $row->id)
                        ->update(['input_hash' => hash('sha256', $canonical)]);
                }
            });

        // 3. NOW create the index — hash-lookup queries
        //    ("find every call whose input matched <known hash>")
        //    use it; nullable column, regular index is fine.
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->index('input_hash', 'idx_mcp_tool_call_audit_input_hash');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->dropIndex('idx_mcp_tool_call_audit_input_hash');
            $table->dropColumn(['input_hash', 'actor']);
        });
    }
};
