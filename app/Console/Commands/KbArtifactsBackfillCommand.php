<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Versioning\SourceRetentionResolver;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * v8.36 / ADR 0030 §3 — populate artifacts for rows ingested before
 * `KB_CONVERSION_ARTIFACTS_ENABLED` was on. Operator-only maintenance, a
 * documented R44 exception: a storage repair that re-converts (and, for an
 * OCR'd source, can reuse or spend a recorded run), so the console is its
 * authorization boundary.
 *
 * Per live row without an artifact: `reference_only` retention → nothing
 * (`intentionally_missing`); source gone from disk → `source_missing`;
 * conversion throws → `conversion_failed`; converted Markdown hashes to the
 * row's `document_hash` → artifact written (`written`); anything else →
 * `hash_mismatch`, nothing written — a stored artifact must be THE bytes
 * the row's version hash names, never a fresh reconversion under a newer
 * converter passed off as history.
 */
final class KbArtifactsBackfillCommand extends Command
{
    protected $signature = 'kb:artifacts-backfill
                            {--project= : Restrict to one project key}
                            {--tenant=default : Tenant whose rows are backfilled}
                            {--dry-run : Report what would be written without touching the disk or the rows}';

    protected $description = 'Store the conversion artifact of live documents that have none (ADR 0030); refuses hash mismatches';

    public function handle(
        ConversionArtifactStore $store,
        SourceRetentionResolver $retention,
        PipelineRegistry $registry,
        TenantContext $tenants,
    ): int {
        $tenant = trim((string) $this->option('tenant'));
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        if (! $store->enabled()) {
            $this->error('Conversion artifacts are disabled (KB_CONVERSION_ARTIFACTS_ENABLED=false); nothing to backfill.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $project = trim((string) ($this->option('project') ?? ''));

        $counts = ['written' => 0, 'intentionally_missing' => 0, 'source_missing' => 0, 'hash_mismatch' => 0, 'conversion_failed' => 0];
        $previous = $tenants->current();
        $tenants->set($tenant);
        try {
            if (! $retention->retainsMarkdown()) {
                $counts['intentionally_missing'] = $this->candidates($tenant, $project)->count();
                $this->report($counts, $dryRun);

                return self::SUCCESS;
            }

            $this->candidates($tenant, $project)->chunkById(100, function ($rows) use ($store, $registry, $tenant, $dryRun, &$counts): void {
                foreach ($rows as $row) {
                    $counts[$this->backfill($row, $store, $registry, $tenant, $dryRun)]++;
                }
            });
        } finally {
            $tenants->set($previous);
        }
        $this->report($counts, $dryRun);

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<KnowledgeDocument>
     */
    private function candidates(string $tenant, string $project)
    {
        $query = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('status', 'active')
            ->whereNull('markdown_path');
        if ($project !== '') {
            $query->where('project_key', $project);
        }

        return $query;
    }

    private function backfill(KnowledgeDocument $row, ConversionArtifactStore $store, PipelineRegistry $registry, string $tenant, bool $dryRun): string
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        try {
            $sourcePath = KbPath::normalize((string) $row->source_path);
            $fullPath = $prefix === '' ? $sourcePath : KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            $this->line("  #{$row->id} {$row->source_path}: source_missing (un-normalizable path)");

            return 'source_missing';
        }
        $storage = Storage::disk($disk);
        if (! $storage->exists($fullPath)) {
            $this->line("  #{$row->id} {$sourcePath}: source_missing");

            return 'source_missing';
        }

        try {
            $bytes = (string) $storage->get($fullPath);
            $converted = $registry->resolveConverter((string) $row->mime_type)->convert(new SourceDocument(
                sourcePath: $sourcePath,
                mimeType: (string) $row->mime_type,
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: ['disk' => $disk, 'prefix' => $prefix],
            ));
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed ({$e->getMessage()})");

            return 'conversion_failed';
        }

        $hash = hash('sha256', $converted->markdown);
        if ($hash !== (string) $row->document_hash) {
            $this->line("  #{$row->id} {$sourcePath}: hash_mismatch (reconversion differs from the recorded version; nothing written)");

            return 'hash_mismatch';
        }

        $final = $store->pathFor($tenant, (string) $row->project_key, $sourcePath, (string) $row->version_hash, $prefix);
        if ($dryRun) {
            $this->line("  #{$row->id} {$sourcePath}: would write {$final}");

            return 'written';
        }
        // Pointer first, bytes second — the same order as ingest (row commits
        // with the path, then the move): the orphan sweep only deletes files
        // no row points at, so a file published before its pointer would be
        // inside that window. On failure the pointer is taken back.
        $row->update(['markdown_path' => $final, 'content_hash' => $hash]);
        try {
            $tmp = $store->writeTemp($disk, $final, $converted->markdown);
            $store->publish($disk, $tmp, $final);
        } catch (\Throwable $e) {
            $row->update(['markdown_path' => null, 'content_hash' => null]);
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed (could not publish: {$e->getMessage()})");

            return 'conversion_failed';
        }
        $this->line("  #{$row->id} {$sourcePath}: written {$final}");

        return 'written';
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function report(array $counts, bool $dryRun): void
    {
        $this->info(sprintf(
            'written=%d intentionally_missing=%d source_missing=%d hash_mismatch=%d conversion_failed=%d%s',
            $counts['written'],
            $counts['intentionally_missing'],
            $counts['source_missing'],
            $counts['hash_mismatch'],
            $counts['conversion_failed'],
            $dryRun ? ' (dry-run)' : '',
        ));
    }
}
