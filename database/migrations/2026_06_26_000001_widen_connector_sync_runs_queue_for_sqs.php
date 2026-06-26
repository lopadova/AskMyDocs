<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `connector_sync_runs.queue` from varchar(64) to varchar(255).
 *
 * {@see \App\Connectors\ConnectorSyncRunRecorder::onProcessing} records the
 * run's queue via `$event->job->getQueue()`. On Laravel Cloud the queue driver
 * is SQS, and `getQueue()` returns the FULL SQS queue URL, e.g.
 *   https://sqs.eu-central-1.amazonaws.com/375391317769/kb-ingest-env-<uuid>
 * (~100+ chars). That overflowed the original 64-char column, so every insert
 * failed with SQLSTATE[22001] "value too long for type character varying(64)".
 * The recorder's try/catch kept the sync/ingest path alive (recording is
 * best-effort), but NO sync-run history was persisted on SQS deployments and
 * the log filled with warnings. 255 comfortably holds an SQS URL; local/redis
 * drivers (short queue name like `kb-ingest`) are unaffected.
 *
 * Driver handling mirrors the v7.0/W6.3 `mcp_tool_call_audit` widen:
 *   - pgsql: explicit `ALTER COLUMN ... TYPE varchar(255)` — Postgres enforces
 *            the length, so this is the load-bearing branch (prod).
 *   - mysql/mariadb: `change()` (no doctrine/dbal needed on Laravel 11+).
 *   - sqlite: NO-OP — SQLite does not enforce varchar length, so the test
 *            driver already accepts the long value; skipping avoids an
 *            unnecessary table rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return; // SQLite ignores varchar length — nothing to widen.
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE connector_sync_runs ALTER COLUMN queue TYPE varchar(255)');

            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table): void {
            $table->string('queue', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            // Best-effort re-narrow: truncate to 64 via USING so the type
            // change cannot crash mid-rollback on a row holding an SQS URL.
            DB::statement(
                'ALTER TABLE connector_sync_runs '
                .'ALTER COLUMN queue TYPE varchar(64) USING substring(queue from 1 for 64)'
            );

            return;
        }

        Schema::table('connector_sync_runs', function (Blueprint $table): void {
            $table->string('queue', 64)->nullable()->change();
        });
    }
};
