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
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->char('input_hash', 64)->nullable()->after('tool_name');
            $table->string('actor', 100)->nullable()->after('user_id');

            // Hash-lookup queries (e.g. "find every call whose input
            // matched <known hash>") need an index; the column is
            // nullable so a regular index is fine.
            $table->index('input_hash', 'idx_mcp_tool_call_audit_input_hash');
        });

        // Backfill the new `input_hash` column for every pre-existing
        // row. We chunk by primary key so the migration scales past a
        // few thousand rows without blowing memory; SHA-256 is run in
        // PHP so the migration is portable across SQLite / Postgres /
        // MySQL — `sha2()` exists on MySQL only, and Postgres needs
        // `pgcrypto`.
        DB::table('mcp_tool_call_audit')
            ->whereNull('input_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = $row->input_json_redacted ?? '';
                    // SQLite stores JSON columns as plain text;
                    // Postgres / MySQL return decoded values via
                    // their respective drivers. Normalise to the
                    // canonical UTF-8 JSON string the package uses
                    // for its own hash so the values join.
                    $canonical = is_string($payload)
                        ? $payload
                        : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    DB::table('mcp_tool_call_audit')
                        ->where('id', $row->id)
                        ->update(['input_hash' => hash('sha256', $canonical)]);
                }
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
