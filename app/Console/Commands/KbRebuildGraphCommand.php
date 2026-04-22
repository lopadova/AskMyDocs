<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the canonical knowledge graph (kb_nodes + kb_edges) from scratch.
 *
 * Use cases:
 *   - schema evolved and existing graph rows carry stale structure;
 *   - a batch re-ingest populated frontmatter_json but the job queue was
 *     disabled / backed up;
 *   - as a nightly consistency sweep (scheduled at 03:40 daily).
 *
 * Strategy: truncate kb_edges + kb_nodes for the target scope, then
 * dispatch CanonicalIndexerJob for every canonical document. Memory-safe
 * (R3): documents walked via chunkById(100).
 *
 * Idempotent and safe to run on-demand. A no-op when no canonical docs
 * exist in the tenant.
 */
class KbRebuildGraphCommand extends Command
{
    protected $signature = 'kb:rebuild-graph
        {--project= : Limit to a single project_key (default: all projects)}
        {--no-truncate : Skip the initial delete of existing nodes/edges (additive rebuild)}
        {--sync : Run indexer jobs synchronously instead of dispatching to the queue}';

    protected $description = 'Rebuild canonical kb_nodes + kb_edges from existing canonical documents.';

    public function handle(): int
    {
        $projectKey = (string) ($this->option('project') ?? '');
        // Default: wipe nodes/edges in scope before rebuilding. Operators can
        // opt into an additive rebuild with --no-truncate when they want to
        // merge into an existing graph (rare — mostly useful during migrations).
        $truncateFirst = ! (bool) $this->option('no-truncate');
        $sync = (bool) $this->option('sync');

        $query = KnowledgeDocument::query()->where('is_canonical', true);
        if ($projectKey !== '') {
            $query->where('project_key', $projectKey);
        }

        $totalDocs = (clone $query)->count();
        if ($totalDocs === 0) {
            $this->info($projectKey === ''
                ? 'No canonical documents found across all projects. Nothing to do.'
                : "No canonical documents found for project '{$projectKey}'. Nothing to do.");
            return self::SUCCESS;
        }

        if ($truncateFirst) {
            $this->truncateGraph($projectKey);
        }

        $scope = $projectKey === '' ? 'all projects' : "project '{$projectKey}'";
        $this->info("Rebuilding graph for {$scope}: {$totalDocs} canonical document(s).");

        $dispatched = 0;
        $query->orderBy('id')->chunkById(100, function ($docs) use (&$dispatched, $sync) {
            foreach ($docs as $doc) {
                if ($sync) {
                    (new CanonicalIndexerJob($doc->id))->handle();
                } else {
                    CanonicalIndexerJob::dispatch($doc->id);
                }
                $dispatched++;
            }
        });

        $this->info($sync
            ? "Rebuilt graph for {$dispatched} document(s) synchronously."
            : "Dispatched {$dispatched} indexer job(s) to the queue. Monitor with `php artisan queue:work`.");

        return self::SUCCESS;
    }

    private function truncateGraph(string $projectKey): void
    {
        DB::transaction(function () use ($projectKey) {
            if ($projectKey === '') {
                KbEdge::query()->delete();
                KbNode::query()->delete();
                $this->line('Truncated kb_edges + kb_nodes (all projects).');
                return;
            }
            // Tenant-scoped truncate. Edges cascade on node delete, but we
            // delete edges explicitly first so the intent is clear.
            KbEdge::where('project_key', $projectKey)->delete();
            KbNode::where('project_key', $projectKey)->delete();
            $this->line("Truncated kb_edges + kb_nodes for project '{$projectKey}'.");
        });
    }
}
