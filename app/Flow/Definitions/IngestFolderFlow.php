<?php

declare(strict_types=1);

namespace App\Flow\Definitions;

use App\Flow\Steps\Folder\DispatchIngestFanOutStep;
use App\Flow\Steps\Folder\ListFolderFilesStep;
use App\Flow\Steps\Folder\PruneOrphansStep;
use Padosoft\LaravelFlow\FlowEngine;

/**
 * `kb.ingest-folder` — 3-step refactor of
 * {@see \App\Console\Commands\KbIngestFolderCommand}.
 *
 * Steps:
 *   1. list-files               (dry-run-safe)
 *      Walks the disk under `input['base_path']`, optionally recursive,
 *      filters by `input['extensions']` (default: SourceType::knownExtensions),
 *      and bounds by `input['limit']`.
 *   2. dispatch-ingest-fan-out  (mutates queue/DB)
 *      For each matched file: when `input['sync']` is true, calls
 *      DocumentIngestor::ingest() inline; otherwise dispatches
 *      IngestDocumentJob (each becomes its own kb.ingest sub-flow).
 *      Per-file failures are accumulated in the output, NOT thrown,
 *      so one bad file doesn't abort the whole fan-out — mirrors the
 *      original CLI's behaviour where unsupported extensions surface
 *      as `! failed:` lines and the rest of the batch keeps dispatching.
 *      The CLI wrapper translates `failure_count > 0` into a non-zero
 *      exit code so the operator's terminal still surfaces the issue.
 *   3. prune-orphans            (mutates DB+disk; skipped when prune_orphans=false)
 *      Optional. Removes documents under base_path whose source file
 *      no longer exists on disk. Honours `force_delete` for the
 *      hard/soft choice.
 *
 * Idempotency: the CLI builds a deterministic-but-fresh idempotencyKey
 * (`ingest-folder:{tenant}:{disk}:{base}:{hrtime}`) so re-runs after
 * manual file edits ALWAYS re-execute. Without the hrtime nonce the
 * engine's per-(name, key) dedup would short-circuit the second run
 * and skip newly-added files.
 *
 * Tenant fan-out: the CLI dispatches ONE Flow execute call per
 * --tenant value (default: just the configured tenant). The folder
 * walk itself is tenant-agnostic; the dispatch carries tenant_id so
 * each per-file IngestDocumentJob re-binds the right TenantContext.
 */
final class IngestFolderFlow
{
    public const NAME = 'kb.ingest-folder';

    public static function register(FlowEngine $engine): void
    {
        $engine->define(self::NAME)
            ->withInput([
                // R30/R31 — tenant_id rides the input bag.
                'tenant_id',
                'project_key',
                'disk',
                'base_path',
                // Optional inputs (no validation — handlers default safely):
                //   - extensions:         list<string> (default: SourceType::knownExtensions)
                //   - recursive:          bool         (default: false)
                //   - sync:               bool         (default: false)
                //   - limit:              int          (default: 0 = unlimited)
                //   - prefix:             string       (KB_PATH_PREFIX, default: '')
                //   - prune_orphans:      bool         (default: false)
                //   - force_delete:       ?bool        (default: null = honour KB_SOFT_DELETE_ENABLED)
                //   - relative_base_path: string       (the user-supplied path,
                //                                       prefix-stripped — used by
                //                                       PruneOrphansStep for the
                //                                       LIKE filter).
            ])
            ->step('list-files', ListFolderFilesStep::class)
                ->withDryRun(true)
            ->step('dispatch-ingest-fan-out', DispatchIngestFanOutStep::class)
            ->step('prune-orphans', PruneOrphansStep::class)
            ->register();
    }
}
