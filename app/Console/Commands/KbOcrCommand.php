<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * v8.36 / ADR 0029 — the PHP surface of OCR (R44).
 *
 *   kb:ocr {document}            re-run OCR on a document (re-dispatches its
 *                                ingestion with OCR forced; a new version is
 *                                born only when the text changed)
 *   kb:ocr {document} --status   what OCR recorded (driver, pages, confidence)
 *
 * `{document}` is a knowledge_documents id. `--tenant=` binds the tenant
 * (default `default`); every read is tenant-scoped (R30).
 */
final class KbOcrCommand extends Command
{
    protected $signature = 'kb:ocr
        {document : knowledge_documents id}
        {--status : Show what OCR recorded instead of re-running}
        {--tenant=default : Tenant to operate in}';

    protected $description = 'Re-run OCR on a document, or show its OCR status (driver, pages, confidence).';

    public function handle(OcrService $ocr, TenantContext $tenants): int
    {
        $tenantId = trim((string) $this->option('tenant'));
        // Same contract as kb:reembed-project: a blank --tenant= would bind an
        // invalid context and resolve the document in the wrong tenant (R30).
        if ($tenantId === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        $previous = $tenants->current();
        $tenants->set($tenantId);

        try {
            $document = KnowledgeDocument::query()->forTenant($tenantId)->find((int) $this->argument('document'));
            if ($document === null) {
                $this->error("Document {$this->argument('document')} not found in tenant [{$tenantId}].");

                return self::FAILURE;
            }

            if ((bool) $this->option('status')) {
                return $this->renderStatus($ocr->status($document));
            }

            try {
                $result = $ocr->rerun($document, 'cli:kb:ocr');
            } catch (HttpException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf(
                'OCR re-run queued for document %d (%s) with driver "%s" [flow run %s — the OCR run key is recorded on the row once the job has run: --status].',
                $result['document_id'],
                $result['source_path'],
                $result['driver'],
                $result['flow_run_key'],
            ));

            return self::SUCCESS;
        } finally {
            $tenants->set($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function renderStatus(array $status): int
    {
        $this->line(sprintf('OCR enabled: %s · driver: %s (%s)', $status['enabled'] ? 'yes' : 'no', $status['driver_configured'], $status['driver_available'] ? 'available' : 'unavailable'));
        if (! $status['ocr']) {
            $this->line('This document was not produced by OCR.');

            return self::SUCCESS;
        }
        $this->line(sprintf(
            'Ran at %s · driver %s%s · reason %s · pages %s · mean confidence %s · min %s · figures %d',
            (string) ($status['ran_at'] ?? '-'),
            (string) ($status['driver'] ?? '-'),
            ($status['remote'] ?? false) ? ' (remote)' : '',
            (string) ($status['reason'] ?? '-'),
            $status['page_count'] === null ? '-' : (string) $status['page_count'],
            $status['mean_confidence'] === null ? '-' : (string) $status['mean_confidence'],
            $status['min_confidence'] === null ? '-' : (string) $status['min_confidence'],
            (int) $status['figures'],
        ));
        $this->table(
            ['Page', 'Confidence', 'Figures', 'Chunks'],
            array_map(static fn (array $p): array => [
                $p['number'],
                $p['confidence'] === null ? '-' : (string) $p['confidence'],
                $p['figures'],
                $p['chunks'],
            ], $status['pages']),
        );

        return self::SUCCESS;
    }
}
