<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite test-bench mirror of
 * `padosoft/askmydocs-connector-base` migration
 * `2026_06_22_000002_backfill_project_key_from_config_json` (v1.3.1).
 * Copied VERBATIM — see the sibling
 * `2026_06_22_000001_*` mirror for the filename-dedup convention.
 *
 * Moves a legacy `config_json['project_key']` into the `project_key`
 * column (only when the column is still empty) and strips the key from
 * `config_json`, so after it runs the column is the single source of
 * truth. Idempotent; JSON decoded/encoded in PHP (portable across the
 * SQLite test bench + Postgres + MySQL); R3 memory-safe via chunkById.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('connector_installations')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $config = json_decode((string) ($row->config_json ?? ''), true);
                    if (! is_array($config) || ! array_key_exists('project_key', $config)) {
                        continue;
                    }

                    $legacy = $config['project_key'];

                    unset($config['project_key']);

                    $update = ['config_json' => json_encode($config)];

                    $column = $row->project_key ?? null;
                    if ((! is_string($column) || $column === '') && is_string($legacy) && $legacy !== '') {
                        $update['project_key'] = $legacy;
                    }

                    DB::table('connector_installations')
                        ->where('id', $row->id)
                        ->update($update);
                }
            });
    }

    public function down(): void
    {
        // No-op: a move is not reliably reversible (the original
        // config_json shape is not retained), and the column remains a
        // valid, readable source either way.
    }
};
