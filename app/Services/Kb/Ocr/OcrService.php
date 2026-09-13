<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use App\FinOps\OcrCallMeter;
use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Pipeline\ConvertedDocument;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * v8.36 / ADR 0029 — the one OCR core every surface adapts (R44):
 *
 *  - `convert()`  — SourceDocument → ConvertedDocument through the configured
 *                   driver; called by OcrConverter (images) and by the
 *                   PdfConverter fallback (scanned PDFs).
 *  - `status()`   — what OCR recorded on a document (driver, pages,
 *                   confidence, figures) read from the document metadata and
 *                   the chunk rows; the read surface of `kb:ocr --status`,
 *                   `GET …/ocr` and KbOcrStatusTool.
 *  - `rerun()`    — re-dispatch the ingestion of a document with OCR forced;
 *                   the write surface of `kb:ocr` and `POST …/ocr`
 *                   (human-only by design — no MCP write tool).
 *
 * Tenant scope: every document read goes through `forTenant()` (R30).
 */
final class OcrService
{
    public const PROVENANCE = 'ocr';

    /**
     * Backstop TTL of the re-run lock (seconds). The job releases it on its
     * terminal outcome (success, or failed() after the last attempt) and
     * re-arms it at the start of every attempt (`renewRerunLock()`), so the
     * TTL only has to outlive ONE attempt window of IngestDocumentJob —
     * 300 s timeout + the queue `retry_after` + the largest backoff (60 s) —
     * not the whole retry sequence nor the time the job waits in the queue:
     * a lock that lapsed while the job was queued is simply re-acquired at
     * attempt start, and one taken over by a newer re-run in the meantime
     * makes the older job fail loudly instead of running a duplicate paid
     * run. 1 200 s keeps a wide margin over that single window.
     */
    public const RERUN_LOCK_TTL = 1200;

    /**
     * MINIMUM lease of the per-run-directory reservation (seconds). The
     * reservation is held from the recorded-run check through `result.json`,
     * i.e. one driver call plus the figure writes, and a driver call is not
     * bounded by 1 200 s in general: Tesseract and the vision driver work
     * page by page under a per-page timeout, and the page cap allows 200.
     * So the actual lease is sized per run by `leaseFor()` from the
     * driver's declared worst case for the page count already verified by
     * the cap, and this constant is only its floor — a lease that is
     * provably longer than the work it protects, never a fixed guess.
     */
    public const RUN_LOCK_TTL = 1200;

    /** Margin over the driver's declared worst case: the figure writes + `result.json`. */
    public const RUN_LOCK_MARGIN = 120;

    /**
     * The reservation lease for one run: the driver's worst case for
     * `$pages` pages under its own timeouts, plus the write margin, never
     * below RUN_LOCK_TTL. A worker that dies mid-run therefore blocks the
     * directory for at most the time its run could legitimately have taken.
     */
    public static function leaseFor(OcrDriver $driver, int $pages): int
    {
        return max(self::RUN_LOCK_TTL, $driver->maxDurationSeconds(max(1, $pages)) + self::RUN_LOCK_MARGIN);
    }

    public static function runLockKey(string $disk, string $runDir): string
    {
        return 'kb:ocr:run:'.$disk.':'.sha1($runDir);
    }

    public static function rerunLockKey(string $tenantId, int $documentId): string
    {
        return sprintf('kb:ocr:rerun:%s:%d', $tenantId, $documentId);
    }

    /**
     * Release the re-run lock a job carried in its ingest metadata
     * (`metadata.ocr.rerun_lock`). Safe to call when absent or already gone.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function releaseRerunLock(array $metadata): void
    {
        $lock = $metadata['ocr']['rerun_lock'] ?? null;
        if (! is_array($lock) || ! is_string($lock['key'] ?? null) || ! is_string($lock['owner'] ?? null)) {
            return;
        }
        try {
            Cache::restoreLock($lock['key'], $lock['owner'])->release();
        } catch (\Throwable) {
            // A lock store that is down must not fail the ingest — the TTL backstops.
        }
    }

    /**
     * Re-arm the re-run lock a job carries at the start of an attempt.
     * Returns false only when the lock is now held by ANOTHER owner — a
     * newer re-run was queued after this one's lock lapsed — so the caller
     * must fail instead of producing a second paid run. A lock store that is
     * down never blocks the ingest (the TTL backstops, as everywhere else).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function renewRerunLock(array $metadata): bool
    {
        $lock = $metadata['ocr']['rerun_lock'] ?? null;
        if (! is_array($lock) || ! is_string($lock['key'] ?? null) || ! is_string($lock['owner'] ?? null)) {
            return true;
        }
        try {
            // Lapsed while queued: re-acquire under the same owner, fresh TTL.
            if (Cache::lock($lock['key'], self::RERUN_LOCK_TTL, $lock['owner'])->get()) {
                return true;
            }

            // Still ours (within TTL): nothing to do.
            return Cache::restoreLock($lock['key'], $lock['owner'])->isOwnedByCurrentProcess();
        } catch (\Throwable $e) {
            // Uncertainty is not ownership: with the lock store unreachable the
            // job must not walk into a billable run it cannot prove is still
            // its own. A retryable error lets the attempt run once the store
            // is back (the job's tries/backoff), instead of a silent duplicate.
            throw new \RuntimeException('OCR re-run lock store unavailable; retry the attempt: '.$e->getMessage(), 0, $e);
        }
    }

    public function __construct(
        private readonly OcrDriverRegistry $registry,
        private readonly OcrFigureStore $figures,
        private readonly OcrCallMeter $meter,
        private readonly PdfTextLayerProbe $probe,
        private readonly TenantContext $tenants,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('kb.ocr.enabled', false);
    }

    public function driver(): OcrDriver
    {
        return $this->registry->configured();
    }

    public function probe(): PdfTextLayerProbe
    {
        return $this->probe;
    }

    /**
     * Whether the ingest metadata asks for OCR regardless of the text layer
     * (`metadata.ocr.force = true`, set by `rerun()`).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function isForced(array $metadata): bool
    {
        return (bool) (($metadata['ocr'] ?? [])['force'] ?? false);
    }

    /**
     * Ingest-metadata keys only the host may set: `ocr.force` starts a billed
     * engine run, `ocr.rerun_lock` names a lock this job will release,
     * `dry_run` turns the conversion into a preview. They travel on the
     * job the host builds (`rerun()`, ParseMarkdownStep) — never on the
     * metadata a client or a connector hands in. Every untrusted boundary
     * (HTTP ingest, connector bridge) strips them.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function stripTrustedOnlyKeys(array $metadata): array
    {
        unset($metadata['dry_run']);
        if (is_array($metadata['ocr'] ?? null)) {
            unset($metadata['ocr']['force'], $metadata['ocr']['rerun_lock']);
            if ($metadata['ocr'] === []) {
                unset($metadata['ocr']);
            }
        }

        return $metadata;
    }

    /**
     * `Flow::dryRun()` reaches the converter through ParseMarkdownStep, which
     * marks the SourceDocument; a dry run must have NO write and NO cost
     * side effect — no driver call, no figure write, no ledger row.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function isDryRun(array $metadata): bool
    {
        return ($metadata['dry_run'] ?? false) === true;
    }

    /**
     * Run the configured driver and render the PdfPageChunker shape.
     *
     * @param  string  $converterName  recorded in extractionMeta.converter
     * @param  string  $reason  `image` | `scanned_pdf` | `forced`
     *
     * @throws OcrDriverUnavailableException
     * @throws \RuntimeException
     */
    public function convert(SourceDocument $doc, string $converterName, string $reason): ConvertedDocument
    {
        if (! $this->enabled()) {
            throw new \RuntimeException('OCR is disabled (KB_OCR_ENABLED=false).');
        }

        $start = hrtime(true);
        $driver = $this->driver();
        if (! $driver->isAvailable()) {
            throw new OcrDriverUnavailableException(sprintf(
                'OCR driver "%s" is not available on this host.',
                $driver->name(),
            ));
        }

        $filename = basename($doc->sourcePath);
        $disk = (string) ($doc->metadata['disk'] ?? config('kb.sources.disk', 'kb'));
        $prefix = array_key_exists('prefix', $doc->metadata)
            ? (string) $doc->metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        $figuresEnabled = (bool) config('kb.ocr.figures.enabled', true);
        // Engine-aware run key: bytes × driver × the driver's variant × the
        // figure switch — anything that shapes the output shapes the key.
        // A forced re-run (kb:ocr) is a NEW attempt with its own identity:
        // runs are immutable and a W2 artifact points at the exact run that
        // produced it, so the previous run is never rewritten in place.
        $variant = $driver->fingerprint().';figures='.($figuresEnabled ? '1' : '0');
        if (self::isForced($doc->metadata)) {
            $variant .= ';attempt='.bin2hex(random_bytes(8));
        }
        $runKey = OcrFigureStore::runKeyFor($doc->bytes, $driver->name(), $variant);

        // Bounded work BEFORE any driver runs or any byte leaves the tenant
        // (SEC-LLM-001 gate 7): a document over the page or size cap fails
        // loudly with a reason instead of being billed page by page.
        $pages = $this->assertWithinLimits($doc->mimeType, $doc->bytes, $filename, $driver);

        // Idempotency (CLAUDE.md §5): the same bytes through the same driver
        // produce the same result — reuse the recorded run instead of paying
        // for it again (a re-ingest of identical bytes, an IMAP backfill, a
        // GH-action full sync). `ocr.force` (kb:ocr) bypasses the reuse.
        $reused = null;
        if (! self::isForced($doc->metadata) && (bool) config('kb.ocr.reuse.enabled', true)) {
            $reused = $this->reusableResult($disk, $doc->sourcePath, $prefix, $runKey, $driver->name());
        }

        if ($reused === null && self::isDryRun($doc->metadata)) {
            // A dry run previews the shape and the spend; it never runs the
            // driver (paid, remote for some), never writes `.ocr/`, never
            // meters. A recorded run is read-only and may still be shown.
            return $this->dryRunPreview($doc, $converterName, $reason, $driver, $runKey, $start);
        }

        if ($reused !== null) {
            $result = $reused['result'];
            $written = $reused['written'];
        } else {
            // A run directory is immutable, so its FIRST write is reserved
            // atomically: the reservation covers the recorded-run check, the
            // driver call, the figure writes and `result.json`. A concurrent
            // ingest of the same bytes waits here, looks again, and reuses
            // the run the first worker recorded — one bill, one directory,
            // never two nondeterministic remote results interleaved in it.
            // Needs an atomic lock store (Redis in production).
            $runDir = $this->figures->runDirFor($doc->sourcePath, $prefix, $runKey);
            $reservation = Cache::lock(self::runLockKey($disk, $runDir), self::leaseFor($driver, $pages));
            try {
                $reservation->block(max(1, (int) config('kb.ocr.run_lock.wait_seconds', 300)));
            } catch (LockTimeoutException) {
                throw new \RuntimeException(sprintf(
                    'OCR run already in progress for "%s" (run %s); retry once it has been recorded.',
                    $filename,
                    $runKey,
                ));
            }
            try {
                if (! self::isForced($doc->metadata) && (bool) config('kb.ocr.reuse.enabled', true)) {
                    $reused = $this->reusableResult($disk, $doc->sourcePath, $prefix, $runKey, $driver->name());
                }
                if ($reused !== null) {
                    $result = $reused['result'];
                    $written = $reused['written'];
                } else {
                    $result = $driver->recognise(new OcrRequest(
                        bytes: $doc->bytes,
                        mimeType: $doc->mimeType,
                        filename: $filename,
                        options: is_array($doc->metadata['ocr'] ?? null) ? $doc->metadata['ocr'] : [],
                    ));

                    $allFigures = [];
                    foreach ($result->pages as $page) {
                        foreach ($page->figures as $figure) {
                            $allFigures[] = $figure;
                        }
                    }
                    $written = $figuresEnabled ? $this->figures->store($disk, $doc->sourcePath, $prefix, $runKey, $allFigures) : [];
                    $this->meter->meter($result, $driver, $doc->sourcePath);
                    if ((bool) config('kb.ocr.reuse.enabled', true)) {
                        $this->recordRun($disk, $doc->sourcePath, $prefix, $runKey, $result, $written);
                    }
                }
            } finally {
                $reservation->release();
            }
        }

        $markdown = $this->renderMarkdown($filename, $result, $figuresEnabled);
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $pagesMeta = array_map(static fn (OcrPage $p): array => [
            'number' => $p->number,
            'confidence' => $p->confidence,
            'figures' => count($p->figures),
            'chars' => mb_strlen($p->markdown),
        ], $result->pages);

        return new ConvertedDocument(
            markdown: $markdown,
            mediaItems: $written,
            extractionMeta: [
                'converter' => $converterName,
                'duration_ms' => $durationMs,
                'page_count' => $result->pageCount(),
                'extraction_strategy' => 'ocr',
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
                // ADR 0029 §6 — how the text was obtained. Orthogonal to the
                // ADR 0028 `provenance_tier` (who authored it), which stays
                // whatever the connector declared.
                'provenance' => self::PROVENANCE,
                'ocr' => [
                    'driver' => $driver->name(),
                    // Audited on the document: did the bytes leave the tenant?
                    'remote' => $driver->isRemote(),
                    'reason' => $reason,
                    'pages' => $pagesMeta,
                    'mean_confidence' => $result->meanConfidence(),
                    'min_confidence' => $result->minConfidence(),
                    'figures' => count($written),
                    'run' => $runKey,
                    // true when the recorded run was reused (no driver call, no spend)
                    'reused' => $reused !== null,
                    'dry_run' => self::isDryRun($doc->metadata),
                    'figures_dir' => $written === [] ? null : $this->figures->runDirFor($doc->sourcePath, $prefix, $runKey),
                    'engine' => $result->meta,
                    'ran_at' => now()->toIso8601String(),
                ],
            ],
            sourceMimeType: $doc->mimeType,
        );
    }

    /**
     * @throws OcrLimitExceededException
     */
    private function assertWithinLimits(string $mimeType, string $bytes, string $filename, OcrDriver $driver): int
    {
        $maxBytes = max(1, (int) config('kb.ocr.max_bytes', 26214400));
        if (strlen($bytes) > $maxBytes) {
            throw new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": %d bytes exceed KB_OCR_MAX_BYTES (%d).',
                $filename,
                strlen($bytes),
                $maxBytes,
            ), 'too_many_bytes');
        }

        $maxPages = max(1, (int) config('kb.ocr.max_pages', 200));
        ['pages' => $pages, 'exact' => $exact] = $this->pageCountDetailFor($mimeType, $bytes);
        // A lower bound cannot enforce a maximum: when the parser could not
        // read the PDF the count is a `/Type /Page` floor, and a malformed
        // or hostile file with more real pages than visible page objects
        // would pass the cap. So an uncountable PDF runs only where the
        // work is bounded by construction (ADR 0029 §4): never on a remote
        // driver (that is egress of an unbounded document), and only on a
        // local driver that renders page by page up to KB_OCR_MAX_PAGES
        // under a per-page timeout — a whole-file engine gets nothing it
        // could not be told the size of.
        if (! $exact && ($driver->isRemote() || ! $driver->boundsWorkWithoutPageCount())) {
            throw new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": the page count could not be verified (the PDF could not be parsed) and driver "%s" %s; an unverifiable document only runs where the work is bounded by construction.',
                $filename,
                $driver->name(),
                $driver->isRemote() ? 'is remote' : 'does not bound its own work',
            ), 'pages_uncountable');
        }
        if ($pages > $maxPages) {
            throw new OcrLimitExceededException(sprintf(
                'OCR refused for "%s": %d pages exceed KB_OCR_MAX_PAGES (%d).',
                $filename,
                $pages,
                $maxPages,
            ), 'too_many_pages');
        }

        return $pages;
    }

    /**
     * Page count before conversion, format-independent: the probe's parser
     * count for a PDF (a `/Type /Page` floor when the parser cannot read
     * it — the same number the estimate shows), the IFD chain length for a
     * TIFF (a multi-page TIFF is N pages of egress and spend), 1 for any
     * other image.
     */
    public function pageCountFor(SourceDocument $doc): int
    {
        return $this->pageCountForBytes($doc->mimeType, $doc->bytes);
    }

    /**
     * The ONE page-count function: the cap enforced by `assertWithinLimits()`
     * and the estimate shown before commit (`OcrCostEstimator`) both read it
     * (directly, or through the probe it wraps), so they cannot disagree.
     * The MIME is only trusted to tell PDF from raster: uploads, connectors
     * and `knowledge_documents` all carry the family MIME (`image/png` for
     * every raster, whatever the extension), so a TIFF is recognised from its
     * bytes — `TiffFrameCounter` answers the IFD chain length for a TIFF
     * header and 1 for any other image. `exact` is false only for a PDF the
     * parser could not read, where `pages` is the `/Type /Page` object floor
     * — a number the estimate may show but the cap must never trust for a
     * remote driver. Images are always exact: the IFD chain is the document.
     *
     * @return array{pages: int, exact: bool}
     */
    public function pageCountDetailFor(string $mimeType, string $bytes): array
    {
        $mime = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if ($mime === 'application/pdf') {
            $probe = $this->probe->probe($bytes);

            return ['pages' => (int) ($probe['pages_total'] ?? 0), 'exact' => (bool) ($probe['pages_exact'] ?? false)];
        }
        if (str_starts_with($mime, 'image/')) {
            return ['pages' => TiffFrameCounter::count($bytes), 'exact' => true];
        }

        return ['pages' => 1, 'exact' => true];
    }

    /** The `pages` projection of `pageCountDetailFor()` for callers that only need the number. */
    public function pageCountForBytes(string $mimeType, string $bytes): int
    {
        return $this->pageCountDetailFor($mimeType, $bytes)['pages'];
    }

    /**
     * @return array{result: OcrResult, written: list<array{path: string, page: int, index: int, bytes: int, relative: string}>}|null
     */
    private function reusableResult(string $disk, string $sourcePath, string $prefix, string $runKey, string $driverName): ?array
    {
        $recorded = $this->figures->loadResult($disk, $sourcePath, $prefix, $runKey);
        if ($recorded === null || ($recorded['driver'] ?? null) !== $driverName || ! is_array($recorded['pages'] ?? null)) {
            return null;
        }

        $storage = Storage::disk($disk);
        $written = [];
        foreach ((array) ($recorded['figures'] ?? []) as $figure) {
            if (! is_array($figure) || ! isset($figure['path']) || ! $storage->exists((string) $figure['path'])) {
                // A figure went missing (manual cleanup): the run is stale, re-run.
                return null;
            }
            $written[] = [
                'path' => (string) $figure['path'],
                'relative' => (string) ($figure['relative'] ?? ''),
                'page' => (int) ($figure['page'] ?? 0),
                'index' => (int) ($figure['index'] ?? 0),
                'bytes' => (int) ($figure['bytes'] ?? 0),
            ];
        }

        // Rebuild the figure descriptors (bytes are on disk already, only
        // page/index/extension matter for the Markdown references).
        $figuresByPage = [];
        foreach ($written as $entry) {
            $ext = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION)) ?: 'png';
            $figuresByPage[$entry['page']][] = new OcrFigure($entry['page'], $entry['index'], '', $ext);
        }

        $pages = [];
        foreach ($recorded['pages'] as $page) {
            if (! is_array($page)) {
                return null;
            }
            $number = (int) ($page['number'] ?? 0);
            $pages[] = new OcrPage(
                number: $number,
                markdown: (string) ($page['markdown'] ?? ''),
                confidence: isset($page['confidence']) ? (float) $page['confidence'] : null,
                figures: $figuresByPage[$number] ?? [],
            );
        }

        return [
            'result' => new OcrResult(driver: $driverName, pages: $pages, meta: (array) ($recorded['meta'] ?? [])),
            'written' => $written,
        ];
    }

    /**
     * @param  list<array{path: string, page: int, index: int, bytes: int, relative: string}>  $written
     */
    private function recordRun(string $disk, string $sourcePath, string $prefix, string $runKey, OcrResult $result, array $written): void
    {
        $this->figures->storeResult($disk, $sourcePath, $prefix, $runKey, [
            'driver' => $result->driver,
            'recorded_at' => now()->toIso8601String(),
            'meta' => $result->meta,
            'pages' => array_map(static fn (OcrPage $p): array => [
                'number' => $p->number,
                'markdown' => $p->markdown,
                'confidence' => $p->confidence,
            ], $result->pages),
            'figures' => $written,
        ]);
    }

    /**
     * What OCR recorded on a document. Reads the immutable ingest metadata
     * (`metadata.converter.ocr`) and the per-chunk confidence through the
     * chunk relationship — the same rows the retrieval path reads.
     *
     * @return array{
     *   document_id: int, enabled: bool, driver_configured: string, driver_available: bool,
     *   ocr: bool, ran_at: ?string, driver: ?string, remote: ?bool, reason: ?string, page_count: ?int,
     *   mean_confidence: ?float, min_confidence: ?float, figures: int, figures_dir: ?string,
     *   pages: list<array{number:int, confidence:?float, figures:int, chunks:int}>
     * }
     */
    public function status(KnowledgeDocument $document): array
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $converter = is_array($metadata['converter'] ?? null) ? $metadata['converter'] : [];
        $ocr = is_array($converter['ocr'] ?? null) ? $converter['ocr'] : null;

        $chunkPages = KnowledgeChunk::query()
            ->forTenant($this->tenants->current())
            ->where('knowledge_document_id', $document->id)
            ->get(['metadata'])
            ->reduce(function (array $carry, KnowledgeChunk $chunk): array {
                $meta = is_array($chunk->metadata) ? $chunk->metadata : [];
                $page = isset($meta['page']) ? (int) $meta['page'] : null;
                if ($page === null) {
                    return $carry;
                }
                $carry[$page] = ($carry[$page] ?? 0) + 1;

                return $carry;
            }, []);

        $pages = [];
        foreach ((array) ($ocr['pages'] ?? []) as $page) {
            if (! is_array($page) || ! isset($page['number'])) {
                continue;
            }
            $number = (int) $page['number'];
            $pages[] = [
                'number' => $number,
                'confidence' => isset($page['confidence']) ? (float) $page['confidence'] : null,
                'figures' => (int) ($page['figures'] ?? 0),
                'chunks' => (int) ($chunkPages[$number] ?? 0),
            ];
        }

        $driver = null;
        $available = false;
        try {
            $configured = $this->driver();
            $driver = $configured->name();
            $available = $configured->isAvailable();
        } catch (\Throwable) {
            $driver = (string) config('kb.ocr.driver', 'tesseract');
        }

        return [
            'document_id' => (int) $document->id,
            'enabled' => $this->enabled(),
            'driver_configured' => (string) $driver,
            'driver_available' => $available,
            'ocr' => $ocr !== null,
            'ran_at' => $ocr['ran_at'] ?? null,
            'driver' => $ocr['driver'] ?? null,
            'remote' => isset($ocr['remote']) ? (bool) $ocr['remote'] : null,
            'reason' => $ocr['reason'] ?? null,
            'page_count' => isset($converter['page_count']) ? (int) $converter['page_count'] : null,
            'mean_confidence' => isset($ocr['mean_confidence']) ? (float) $ocr['mean_confidence'] : null,
            'min_confidence' => isset($ocr['min_confidence']) ? (float) $ocr['min_confidence'] : null,
            'figures' => (int) ($ocr['figures'] ?? 0),
            'figures_dir' => $ocr['figures_dir'] ?? null,
            'pages' => $pages,
        ];
    }

    /**
     * Re-run OCR on a document: re-dispatches its ingestion with OCR forced
     * and a fresh flow idempotency key, so the run is not short-circuited to
     * the original one. The ingest metadata carries `version_actor` /
     * `version_reason` for the W2 artifact columns (ADR 0030); an identical
     * result is the usual version-hash no-op. One re-run per document at a
     * time: a second call while the first is queued is a 409, so a double
     * click never queues two paid runs.
     *
     * @return array{dispatched: bool, document_id: int, source_path: string, driver: string, run_key: string}
     *
     * @throws UnprocessableEntityHttpException when OCR is disabled, the driver is
     *   unavailable, the source is not OCR-able, the file is gone from disk, or
     *   the job would refuse it anyway (page/byte cap, unverifiable page count
     *   on a remote driver)
     */
    public function rerun(KnowledgeDocument $document, string $actor): array
    {
        if (! $this->enabled()) {
            throw new UnprocessableEntityHttpException('OCR is disabled (KB_OCR_ENABLED=false).');
        }

        try {
            $driver = $this->driver();
        } catch (OcrDriverUnavailableException $e) {
            // A remote driver with KB_OCR_ALLOW_REMOTE off (or an unknown
            // name) is the same "cannot run here" the line below reports: a
            // 422 with the registry's reason, never a 500.
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
        if (! $driver->isAvailable()) {
            throw new UnprocessableEntityHttpException(sprintf('OCR driver "%s" is not available on this host.', $driver->name()));
        }

        $mime = strtolower(trim(explode(';', (string) $document->mime_type, 2)[0]));
        $ocrable = $mime === 'application/pdf' || in_array($mime, \App\Support\Kb\SourceType::imageMimes(), true);
        if (! $ocrable) {
            throw new UnprocessableEntityHttpException("Document mime type \"{$mime}\" is not OCR-able (PDF or image only).");
        }

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        $sourcePath = KbPath::normalize((string) $document->source_path);
        $fullPath = ltrim(trim($prefix, '/').'/'.ltrim($sourcePath, '/'), '/');

        if (! Storage::disk($disk)->exists($fullPath)) {
            throw new UnprocessableEntityHttpException("Source file not found on disk [{$disk}]: {$sourcePath}.");
        }

        // R14 — the same limits the job enforces, checked here so a re-run
        // the job would refuse (page/byte cap, unverifiable page count on a
        // remote driver) is a 422 now, not a queued failure later.
        try {
            $this->assertWithinLimits((string) $document->mime_type, (string) Storage::disk($disk)->get($fullPath), basename($sourcePath), $driver);
        } catch (OcrLimitExceededException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        // One queued re-run per document: the lock is released by the job on
        // its terminal outcome (IngestDocumentJob::handle success branch /
        // failed(), through OcrService::releaseRerunLock) and by the failure
        // branch below when the dispatch itself fails; the TTL is only the
        // backstop for a worker that dies mid-run. Needs an atomic shared
        // lock store (Redis in production) to hold across pods.
        $lockKey = self::rerunLockKey($this->tenants->current(), (int) $document->id);
        $lock = Cache::lock($lockKey, self::RERUN_LOCK_TTL);
        if (! $lock->get()) {
            throw new ConflictHttpException('An OCR re-run for this document is already queued.');
        }

        $runKey = 'ocr:'.Str::uuid();

        // Only the ingest-time keys travel: the previous row's `converter` /
        // `connector` / `external_*` blocks are outputs of the last run, not
        // inputs to this one (the ingestor rewrites them anyway).
        $ingestMetadata = array_diff_key($metadata, array_flip(['converter', 'connector', 'external_url', 'external_id']));

        try {
            IngestDocumentJob::dispatchForCurrentTenant(
                projectKey: (string) $document->project_key,
                relativePath: $sourcePath,
                disk: $disk,
                title: (string) $document->title,
                metadata: array_merge($ingestMetadata, [
                    'ocr' => array_merge((array) ($metadata['ocr'] ?? []), [
                        'force' => true,
                        'rerun_lock' => ['key' => $lockKey, 'owner' => $lock->owner()],
                    ]),
                    'version_actor' => $actor,
                    'version_reason' => 'ocr re-run ('.$driver->name().')',
                ]),
                mimeType: (string) $document->mime_type,
                runKey: $runKey,
            );
        } catch (\Throwable $e) {
            $lock->release();
            throw $e;
        }

        return [
            'dispatched' => true,
            'document_id' => (int) $document->id,
            'source_path' => $sourcePath,
            'driver' => $driver->name(),
            'run_key' => $runKey,
        ];
    }

    /**
     * What a dry run returns instead of an OCR result: one `## Page n` section
     * per page the driver WOULD see (the PdfPageChunker shape, so the chunk
     * preview is realistic) and the same `ocr` meta block with `dry_run: true`
     * and no text, figures or spend.
     */
    private function dryRunPreview(SourceDocument $doc, string $converterName, string $reason, OcrDriver $driver, string $runKey, int $start): ConvertedDocument
    {
        $filename = basename($doc->sourcePath);
        $pages = max(1, $this->pageCountFor($doc));
        $note = sprintf(
            '_OCR dry run — this page would be sent to the `%s` driver%s; no text was extracted, no figures written, nothing billed._',
            $driver->name(),
            $driver->isRemote() ? ' (remote)' : '',
        );
        $sections = [];
        for ($n = 1; $n <= $pages; $n++) {
            $sections[] = "## Page {$n}\n\n{$note}";
        }

        return new ConvertedDocument(
            markdown: "# {$filename}\n\n".implode("\n\n", $sections)."\n",
            mediaItems: [],
            extractionMeta: [
                'converter' => $converterName,
                'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
                'page_count' => $pages,
                'extraction_strategy' => 'ocr',
                'source_path' => $doc->sourcePath,
                'filename' => $filename,
                'provenance' => self::PROVENANCE,
                'ocr' => [
                    'driver' => $driver->name(),
                    'remote' => $driver->isRemote(),
                    'reason' => $reason,
                    'pages' => [],
                    'mean_confidence' => null,
                    'min_confidence' => null,
                    'figures' => 0,
                    'run' => $runKey,
                    'reused' => false,
                    'dry_run' => true,
                    'figures_dir' => null,
                    'engine' => [],
                ],
            ],
            sourceMimeType: $doc->mimeType,
        );
    }

    private function renderMarkdown(string $filename, OcrResult $result, bool $figuresEnabled): string
    {
        $sections = [];
        foreach ($result->pages as $page) {
            $body = trim($page->markdown);
            if (! $figuresEnabled) {
                // A driver that rewrote its own image links (Docling) must not
                // leave the Markdown citing files that are not stored: strip
                // every generated `images/fig-…` reference, then the empty
                // lines it leaves behind.
                $body = trim((string) preg_replace('/\n{3,}/', "\n\n", (string) preg_replace('/!\[[^\]]*\]\(images\/fig-[^)\s]+\)/', '', $body)));
            }
            if ($figuresEnabled) {
                foreach ($page->figures as $figure) {
                    $ref = sprintf('![Figure %d.%d](images/%s)', $figure->page, $figure->index, $figure->fileName());
                    if (str_contains($body, 'images/'.$figure->fileName())) {
                        continue; // the driver already rewrote the reference
                    }
                    if ($figure->placeholder !== null && $figure->placeholder !== '' && str_contains($body, $figure->placeholder)) {
                        $body = str_replace($figure->placeholder, $ref, $body);
                        continue;
                    }
                    $body = $body === '' ? $ref : $body."\n\n".$ref;
                }
            }
            if ($body === '') {
                continue;
            }
            $sections[] = '## Page '.$page->number."\n\n{$body}\n\n";
        }
        if ($sections === []) {
            return '';
        }

        return "# {$filename}\n\n".rtrim(implode('', $sections))."\n";
    }
}
