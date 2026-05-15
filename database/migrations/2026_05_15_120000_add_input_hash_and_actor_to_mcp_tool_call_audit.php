<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v7.0/W1.B — bridge the AskMyDocs `mcp_tool_call_audit` schema to the
 * `padosoft/askmydocs-mcp-pack` v1.0.0 contract.
 *
 * The package writes `input_hash` (SHA-256 of arguments) and `actor`
 * (string, decoupled from a host User model) on every tool invocation.
 * The host's v5.0 schema lacked both columns — it stored the full
 * redacted-payload (`input_json_redacted`) and the user FK (`user_id`)
 * for operator-forensics. Both audit shapes coexist after this
 * migration:
 *
 *   - The package's hash-only contract is satisfied by `input_hash`
 *     + `actor`.
 *   - The host's operator-forensics audit row is satisfied by the
 *     pre-existing `input_json_redacted` + `user_id`.
 *
 * The host's `App\Models\McpToolCallAudit` subclass fills both sides
 * from its `creating()` hook, so a row written by the package's
 * `ToolInvoker` is indistinguishable from a row written by host code
 * paths that pre-date v7.0.
 *
 * Both new columns are nullable to avoid breaking any in-flight tool
 * call at deploy time. `input_hash` is backfilled for every existing
 * row via SHA-256 over `input_json_redacted` so historical audit rows
 * remain forensically equivalent under either lookup shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->char('input_hash', 64)
                ->nullable()
                ->after('input_json_redacted')
                ->comment('SHA-256 of the package-supplied arguments — populated by the package contract.');

            $table->string('actor', 100)
                ->nullable()
                ->after('user_id')
                ->comment('Free-form actor identifier (package contract). The host writes the User id here as a stringified fallback when no User binding is available.');

            $table->string('mcp_server_name', 150)
                ->nullable()
                ->after('mcp_server_id')
                ->comment('Snapshotted server name at invocation time — survives server renames; matches the package audit shape. (The mcp_server_id FK still cascadeOnDelete, so audit rows do disappear when the parent row is deleted; the comment used to overstate this.)');

            $table->string('error_excerpt', 500)
                ->nullable()
                ->after('error_json')
                ->comment('First 500 chars of any throwable surfaced by the tool — package audit shape.');
        });

        // The host's v5.0 schema required `input_json_redacted`,
        // `user_id`, and `result_hash` on every audit row. The
        // package's ToolInvoker writes the package-shape (input_hash
        // + actor + nullable result_hash) only — error/timeout rows
        // come through with `result_hash` null. Relax all three
        // columns so package-written rows can satisfy the table
        // constraint while pre-existing rows remain valid.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            // SQLite cannot ALTER COLUMN nullability without recreate;
            // the test suite uses SQLite via testbench migrations that
            // already model the post-v7.0 shape, so this branch is a
            // no-op there.
            Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
                $table->json('input_json_redacted')->nullable()->change();
                $table->foreignId('user_id')->nullable()->change();
                $table->string('result_hash', 64)->nullable()->change();
            });
        }

        // Backfill input_hash for every historical row so a forensic
        // SHA-256 lookup matches both pre- and post-migration audit
        // entries. Idempotent: rows that already carry input_hash are
        // left untouched.
        DB::table('mcp_tool_call_audit')
            ->whereNull('input_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $payload = $row->input_json_redacted;
                    // SQLite returns the JSON as a string already; PG /
                    // MySQL hand back the decoded array under JSON casts.
                    if (is_array($payload)) {
                        $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    }
                    if (! is_string($payload) || $payload === '') {
                        continue;
                    }
                    DB::table('mcp_tool_call_audit')
                        ->where('id', $row->id)
                        ->update(['input_hash' => hash('sha256', $payload)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
            $table->dropColumn(['input_hash', 'actor', 'mcp_server_name', 'error_excerpt']);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('mcp_tool_call_audit', function (Blueprint $table): void {
                $table->json('input_json_redacted')->nullable(false)->change();
                $table->foreignId('user_id')->nullable(false)->change();
                $table->string('result_hash', 64)->nullable(false)->change();
            });
        }
    }
};
