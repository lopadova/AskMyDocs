<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Agent\DocumentLineReader;
use App\Services\Kb\Agent\SimilarDocumentFinder;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.44/W4 (ADR 0035 §D6.2/§D6.3) — show a document (or a LINE RANGE of it),
 * addressed by numeric id, slug or source_path. On a miss it prints "did you
 * mean" suggestions. Thin adapter over {@see DocumentLineReader} and
 * {@see SimilarDocumentFinder} (R44).
 *
 *   php artisan kb:show 42 --from=120 --lines=40
 *   php artisan kb:show refund-polcy --project=ops       # → did you mean: refund-policy
 */
final class KbShowCommand extends Command
{
    protected $signature = 'kb:show
        {document : Document id, slug or source_path}
        {--project= : Project key (needed to resolve a slug)}
        {--tenant=default : Tenant that owns the document}
        {--from=1 : First line to show (1-based)}
        {--lines= : Number of lines to show (default and cap: kb.line_read.max_lines)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Show a document or a line range of it; suggests near misses when it is not found.';

    public function handle(DocumentLineReader $documentLineReader, SimilarDocumentFinder $similarDocumentFinder, TenantContext $tenantContext): int
    {
        $needle = trim((string) $this->argument('document'));
        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));
        if ($needle === '' || $tenant === '') {
            $this->error('A document and a non-empty --tenant are required.');

            return self::FAILURE;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            $document = $this->resolve($tenant, $needle, $project);
            if ($document === null) {
                return $this->notFound($similarDocumentFinder, $tenant, $needle, $project);
            }

            $lines = $documentLineReader->read(
                $document,
                max(1, (int) $this->option('from')),
                $this->option('lines') === null || $this->option('lines') === '' ? null : max(1, (int) $this->option('lines')),
            );
        } finally {
            $tenantContext->set($previous);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['document' => ['id' => $document->id, 'source_path' => $document->source_path, 'title' => $document->title]] + $lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf('%s  (lines %d-%d of %d%s)', $document->source_path, $lines['from_line'], $lines['to_line'], $lines['total_lines'], $lines['truncated'] ? ', truncated' : ''));
        $this->line($lines['content']);

        return self::SUCCESS;
    }

    private function resolve(string $tenant, string $needle, string $project): ?KnowledgeDocument
    {
        $q = KnowledgeDocument::query()->forTenant($tenant)->where('status', '!=', 'archived');
        if ($project !== '') {
            $q->where('project_key', $project);
        }

        if (ctype_digit($needle)) {
            return (clone $q)->whereKey((int) $needle)->first();
        }

        return (clone $q)->where(fn ($w) => $w->where('source_path', $needle)->orWhere('slug', $needle))->orderBy('id')->first();
    }

    private function notFound(SimilarDocumentFinder $finder, string $tenant, string $needle, string $project): int
    {
        $this->error("Document [{$needle}] not found.");
        $suggestions = $finder->suggest($tenant, $needle, $project === '' ? null : $project);
        if ($suggestions !== []) {
            $this->line('Did you mean:');
            foreach ($suggestions as $s) {
                $this->line(sprintf('  - %s%s  (%s)', $s['slug'] ?? $s['source_path'], $s['slug'] !== null ? '  '.$s['source_path'] : '', $s['project_key']));
            }
        }

        return self::FAILURE;
    }
}
