<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7.0/W6.3 — finish the audit-table coexistence so the
 * `padosoft/askmydocs-mcp-pack` package writer can persist rows
 * directly (next sub-step, W6.3.B, points
 * `config('mcp-pack.audit_model')` at the host model).
 *
 * Four changes:
 *
 *   1. `user_id` becomes NULLABLE. The package writer only knows the
 *      string `actor` identifier (e.g. `"user:42"` or `"system"`);
 *      a tenant-agnostic library cannot resolve every host's user
 *      table by FK. The host's existing write path still fills
 *      `user_id` — nullable just lets the package skip it.
 *   2. `status` widens from the strict ENUM
 *      `('ok','error','timeout','denied')` to `string(32)`. The
 *      package emits `transport_error` (and may emit other strings
 *      in future versions); a string column accepts any value the
 *      package decides without further migrations.
 *   3. `result_hash` becomes NULLABLE. The package writes `null`
 *      when the tool call FAILED (no result to hash). Host code
 *      that already populates a hex hash keeps doing so; the
 *      column just no longer rejects null inserts.
 *   4. `mcp_server_name` (string, nullable, max 100) — the package
 *      writes the denormalised server name alongside the FK id so
 *      audit reports can render it without an extra join. Host
 *      operator-forensics queries that already JOIN `mcp_servers`
 *      keep working; the new column is a convenience addition.
 *
 * Both moves are forward-compatible — existing host code reads /
 * writes the same values, queries against `status = 'ok'` keep
 * working, joins by `user_id` keep working when the column is
 * populated, and Postgres / MySQL / SQLite all accept the column
 * type change in-place.
 *
 * Driver handling: Laravel 11+ on PHP 8.3+ no longer requires
 * `doctrine/dbal` for the simple `change()` cases used here
 * (nullable / type swap to string / type swap from enum to
 * string) on SQLite + MySQL. Postgres still needs explicit
 * `ALTER COLUMN` statements because `change()` cannot drop the
 * ENUM cleanly on Postgres without a USING expression — handled
 * in the `pgsql` branch via raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add the new `mcp_server_name` column FIRST — driver-
        // independent column adds work the same on every backend.
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->string('mcp_server_name', 100)->nullable()->after('mcp_server_id');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite cannot ALTER COLUMN TYPE in place. Recreate via
            // the `Schema::table` + `change()` path which Laravel
            // implements via a table-rebuild under SQLite.
            Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->change();
                $table->string('status', 32)->default('ok')->change();
                $table->string('result_hash', 64)->nullable()->change();
            });
            return;
        }

        if ($driver === 'pgsql') {
            // Postgres: explicit DROP NOT NULL + ENUM → varchar.
            DB::statement('ALTER TABLE mcp_tool_call_audit ALTER COLUMN user_id DROP NOT NULL');
            DB::statement('ALTER TABLE mcp_tool_call_audit ALTER COLUMN result_hash DROP NOT NULL');
            // Laravel's `enum('status', [...])` on Postgres creates a
            // `varchar` column WITH a CHECK constraint named
            // `<table>_<column>_check` that enforces
            // `status IN ('ok','error','timeout','denied')`. The
            // `ALTER COLUMN ... TYPE varchar(32)` widens the type but
            // does NOT drop that CHECK — so inserts with the new
            // `'transport_error'` value (and any future package-
            // emitted string) would still fail with a constraint
            // violation. Drop the CHECK before the type change.
            // `IF EXISTS` makes this idempotent so it survives hosts
            // that ran through a custom migration path and don't
            // have the constraint named that way.
            DB::statement('ALTER TABLE mcp_tool_call_audit DROP CONSTRAINT IF EXISTS mcp_tool_call_audit_status_check');
            DB::statement(
                "ALTER TABLE mcp_tool_call_audit "
                . "ALTER COLUMN status TYPE varchar(32) USING status::text"
            );
            return;
        }

        // MySQL / MariaDB — the `change()` path works without
        // doctrine for these drivers in Laravel 11+.
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('status', 32)->default('ok')->change();
            $table->string('result_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // **Roll-back is best-effort by design.** Re-narrowing
        // these columns is structurally lossy:
        //
        //  - `user_id` SET NOT NULL would FAIL if any row was
        //    written with `user_id = NULL` after `up()` ran —
        //    which is the whole point of the migration (the
        //    package writer fills `actor` instead of `user_id`).
        //  - `status` re-enum-ing would FAIL on any row carrying
        //    `transport_error` (or any future package-emitted
        //    string outside the original ENUM).
        //  - `result_hash` SET NOT NULL would FAIL on any failed-
        //    call audit row written after `up()`.
        //
        // Restoring NOT NULL without scrubbing data is unsafe AND
        // would crash mid-rollback on the first offending row.
        // Instead `down()` does the one truly safe revert — drops
        // the new `mcp_server_name` column. Operators who need to
        // re-narrow the other columns should scrub the offending
        // rows manually first, then ship a follow-up migration.
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->dropColumn('mcp_server_name');
        });
    }
};
