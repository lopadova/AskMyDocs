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
                            {--limit= : Versions to list (1..the configured maximum, default the maximum)}
                            {--offset=0 : Newest versions to skip (the page cursor)}
                            {--diff= : Diff two versions of the family, as FROM:TO ids}';

    protected $description = 'List a document\'s version family (actor, reason, artifact) and optionally diff two versions';

    public function handle(DocumentVersionService $versions, TenantContext $tenants): int
    {
        $tenant = trim((string) $this->option('tenant'));
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        $documentArgument = trim((string) $this->argument('document'));
        if ($documentArgument === '' || ! ctype_digit($documentArgument) || (int) $documentArgument < 1) {
            // A bare `(int)` cast turns non-numeric input into `0` silently
            // — "Document 0 not found" then blames a row that was never
            // asked for, instead of the argument the operator actually
            // typed. Same `ctype_digit` shape as --limit/--offset below.
            $this->error("Invalid document id: '{$this->argument('document')}'. It must be a positive integer.");

            return self::FAILURE;
        }
        $id = (int) $documentArgument;

        $previous = $tenants->current();
        $tenants->set($tenant);
        try {
            $document = KnowledgeDocument::query()->forTenant($tenant)->find($id);
            if ($document === null) {
                $this->error("Document {$id} not found in tenant '{$tenant}'.");

                return self::FAILURE;
            }

            $limitOption = trim((string) ($this->option('limit') ?? ''));
            if ($limitOption !== '' && (! ctype_digit($limitOption) || (int) $limitOption < 1)) {
                $this->error('--limit must be a positive integer.');

                return self::FAILURE;
            }
            $offsetOption = trim((string) ($this->option('offset') ?? '0'));
            if (! ctype_digit($offsetOption)) {
                $this->error('--offset must be a non-negative integer.');

                return self::FAILURE;
            }
            $limit = DocumentVersionService::timelineLimit($limitOption === '' ? null : (int) $limitOption);
            $offset = (int) $offsetOption;
            $total = $versions->familySizeFor($document);
            $family = $versions->versionsFor($document, $limit, $offset);
            $this->info(sprintf('%s · %s · %d version(s)', $document->project_key, $document->source_path, $total));
            if ($total > $offset + $family->count()) {
                $this->warn(sprintf('Showing versions %d-%d of %d (--limit, max %d; --offset to page).', $offset + 1, $offset + $family->count(), $total, DocumentVersionService::timelineLimit()));
            }
            $this->table(
                ['id', 'status', 'actor', 'reason', 'artifact', 'content_hash', 'indexed_at'],
                $family->map(static fn (KnowledgeDocument $v): array => [
                    (int) $v->id,
                    $v->status === 'active' ? 'live' : (string) $v->status,
                    (string) ($v->version_actor ?? '—'),
                    (string) ($v->version_reason ?? '—'),
                    // ADR 0030 §5 — the verified state (none · verified ·
                    // unverified · missing · mismatch), never the pointer alone.
                    $versions->artifactStateFor($v),
                    is_string($v->content_hash) ? substr($v->content_hash, 0, 12) : '—',
                    $v->indexed_at?->toIso8601String() ?? '—',
                ])->all(),
            );

            $diffOption = trim((string) ($this->option('diff') ?? ''));
            if ($diffOption === '') {
                return self::SUCCESS;
            }

            return $this->printDiff($versions, $document, $diffOption);
        } finally {
            $tenants->set($previous);
        }
    }

    private function printDiff(DocumentVersionService $versions, KnowledgeDocument $document, string $spec): int
    {
        if (preg_match('/^(\d+):(\d+)$/', $spec, $m) !== 1) {
            $this->error('--diff expects FROM:TO version ids.');

            return self::FAILURE;
        }
        // Both ids are resolved against the WHOLE family, not the listed page:
        // a version beyond --limit / --offset is still this document's.
        $from = $versions->versionInFamily($document, (int) $m[1]);
        $to = $versions->versionInFamily($document, (int) $m[2]);
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
