<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.36 / ADR 0030 §9 — the CLI surface of the Time Machine (R44): list a
 * document's version family with its provenance, optionally diff two
 * versions. Reads through the SAME DocumentVersionService the HTTP and MCP
 * surfaces use; `--tenant` is validated non-empty and the document is
 * resolved with `forTenant()`, never the process-global default (the
 * `kb:reembed-project` contract). It reads; restore stays HTTP-only.
 */
final class KbDocVersionsCommand extends Command
{
    protected $signature = 'kb:doc-versions
                            {document : knowledge_documents id of any version in the family}
                            {--tenant=default : Tenant that owns the document}
                            {--diff= : Diff two versions of the family, as FROM:TO ids}';

    protected $description = 'List a document\'s version family (actor, reason, artifact) and optionally diff two versions';

    public function handle(DocumentVersionService $versions, TenantContext $tenants): int
    {
        $tenant = trim((string) $this->option('tenant'));
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        $id = (int) $this->argument('document');

        $previous = $tenants->current();
        $tenants->set($tenant);
        try {
            $document = KnowledgeDocument::query()->forTenant($tenant)->find($id);
            if ($document === null) {
                $this->error("Document {$id} not found in tenant '{$tenant}'.");

                return self::FAILURE;
            }

            $family = $versions->versionsFor($document);
            $this->info(sprintf('%s · %s · %d version(s)', $document->project_key, $document->source_path, $family->count()));
            $this->table(
                ['id', 'status', 'actor', 'reason', 'artifact', 'content_hash', 'indexed_at'],
                $family->map(static fn (KnowledgeDocument $v): array => [
                    (int) $v->id,
                    $v->status === 'active' ? 'live' : (string) $v->status,
                    (string) ($v->version_actor ?? '—'),
                    (string) ($v->version_reason ?? '—'),
                    is_string($v->markdown_path) && $v->markdown_path !== '' ? 'yes' : 'no',
                    is_string($v->content_hash) ? substr($v->content_hash, 0, 12) : '—',
                    $v->indexed_at?->toIso8601String() ?? '—',
                ])->all(),
            );

            $diffOption = trim((string) ($this->option('diff') ?? ''));
            if ($diffOption === '') {
                return self::SUCCESS;
            }

            return $this->printDiff($versions, $family, $diffOption);
        } finally {
            $tenants->set($previous);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, KnowledgeDocument>  $family
     */
    private function printDiff(DocumentVersionService $versions, $family, string $spec): int
    {
        if (preg_match('/^(\d+):(\d+)$/', $spec, $m) !== 1) {
            $this->error('--diff expects FROM:TO version ids.');

            return self::FAILURE;
        }
        $from = $family->first(static fn (KnowledgeDocument $v): bool => (int) $v->id === (int) $m[1]);
        $to = $family->first(static fn (KnowledgeDocument $v): bool => (int) $v->id === (int) $m[2]);
        if ($from === null || $to === null) {
            $this->error('Both --diff ids must belong to this document family.');

            return self::FAILURE;
        }

        $diff = $versions->diff($from, $to);
        $this->info(sprintf(
            'diff #%d (%s) → #%d (%s): +%d / -%d',
            $diff['from'], $diff['from_source'], $diff['to'], $diff['to_source'], $diff['added'], $diff['removed'],
        ));
        foreach ($diff['rows'] as $row) {
            $prefix = match ($row['type']) {
                'add' => '+ ',
                'remove' => '- ',
                default => '  ',
            };
            $this->line($prefix.$row['text']);
        }

        return self::SUCCESS;
    }
}
