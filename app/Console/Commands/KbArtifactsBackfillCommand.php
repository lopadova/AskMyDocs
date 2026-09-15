<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Versioning\SourceRetentionResolver;
use App\Support\KbPath;
use App\Support\Kb\SourceInFlight;
use App\Support\Kb\StorageNamespace;
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
 * Per live row, judged on ITS retention contract (`metadata.source_retention`,
 * a row without the stamp predates v8.36 and counts as `full_copy` — the
 * configured mode of the day decides nothing here, so a history ingested
 * under `full_copy` is still backfilled after the knob moved to
 * `reference_only`): `reference_only` → nothing (`intentionally_missing`);
 * pointer set and its bytes readable + hashing to `document_hash` →
 * `already_stored`; otherwise (no pointer, or a pointer whose file is
 * missing or corrupt — a publish that failed after commit, a process that
 * died between the pointer and the move, a later corruption): source gone
 * from disk → `source_missing`; conversion throws → `conversion_failed`;
 * converted Markdown hashes to the row's `document_hash` → artifact written
 * (`written`); anything else → `hash_mismatch`, nothing written — a stored
 * artifact must be THE bytes the row's version hash names, never a fresh
 * reconversion under a newer converter passed off as history.
 */
final class KbArtifactsBackfillCommand extends Command
{
    protected $signature = 'kb:artifacts-backfill
                            {--project= : Restrict to one project key}
                            {--tenant=default : Tenant whose rows are backfilled}
                            {--dry-run : Report what would be written without touching the disk or the rows}';

    protected $description = 'Store or repair the conversion artifact of live documents, each judged on its own retention contract (ADR 0030); refuses hash mismatches';

    /** Originals removed by this run under a `markdown_only` contract (reported in the summary line). */
    private int $originalsDropped = 0;

    public function handle(
        ConversionArtifactStore $store,
        PipelineRegistry $registry,
        DocumentIngestor $ingestor,
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

        $counts = ['already_stored' => 0, 'written' => 0, 'intentionally_missing' => 0, 'source_missing' => 0, 'hash_mismatch' => 0, 'conversion_failed' => 0, 'ocr_unverified' => 0, 'disk_unavailable' => 0];
        $this->originalsDropped = 0;
        $previous = $tenants->current();
        $tenants->set($tenant);
        try {
            $this->candidates($tenant, $project)->chunkById(100, function ($rows) use ($store, $registry, $ingestor, $tenant, $dryRun, &$counts): void {
                foreach ($rows as $row) {
                    $counts[$this->backfill($row, $store, $registry, $ingestor, $tenant, $dryRun)]++;
                }
            });
        } finally {
            $tenants->set($previous);
        }
        $this->report($counts, $dryRun);

        // A row whose artifact this run could not produce — a disk it could
        // not resolve or that refused it, a source that is gone, a conversion
        // that threw — is a partial failure an operator or a scheduler must
        // see (R14): non-zero, never a green exit that hides it, in a dry run
        // too (these are observations, not actions: the preview predicts the
        // real run's exit, as `kb:prune-archived-versions --dry-run` does).
        // `hash_mismatch` is informational (a recorded version the source no
        // longer produces; nothing to repair from here); a retention tail
        // that could not be finalized is reported on the row's line and
        // retried by the next run, not counted here.
        if (($counts['conversion_failed'] + $counts['disk_unavailable'] + $counts['source_missing']) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<KnowledgeDocument>
     */
    private function candidates(string $tenant, string $project)
    {
        // Every live row: a pointer is not an artifact (it is verified per
        // row below), so rows whose file never landed or went corrupt are
        // repaired, not skipped forever.
        $query = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('status', 'active');
        if ($project !== '') {
            $query->where('project_key', $project);
        }

        return $query;
    }

    private function backfill(KnowledgeDocument $row, ConversionArtifactStore $store, PipelineRegistry $registry, DocumentIngestor $ingestor, string $tenant, bool $dryRun): string
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        // The ROW's contract decides — a persisted invalid value resolves to
        // `full_copy` (the conservative mode), as it does everywhere else.
        $stamp = $metadata['source_retention'] ?? null;
        $rowMode = is_string($stamp) && in_array($stamp, SourceRetentionResolver::MODES, true)
            ? $stamp
            : SourceRetentionResolver::FULL_COPY;
        if ($rowMode === SourceRetentionResolver::REFERENCE_ONLY) {
            return 'intentionally_missing';
        }
        $disk = StorageNamespace::diskOf($metadata);
        $prefix = StorageNamespace::recordedPrefix($metadata);
        // A row whose recorded disk this deployment cannot resolve — or
        // cannot reach when its source is probed below — is ONE reported row
        // in its own bucket (`disk_unavailable`: an operator / infrastructure
        // condition, not a missing source — it re-reports identically until
        // the disk is configured or back), never an abort that leaves every
        // later row unprocessed (R14).
        try {
            $storage = Storage::disk($disk);
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$row->source_path}: disk_unavailable (disk [{$disk}] cannot be resolved here: {$e->getMessage()})");

            return 'disk_unavailable';
        }
        $pointer = $row->markdown_path;
        if (is_string($pointer) && $pointer !== '') {
            // read() never throws: an unreadable pointer is null → repaired below.
            $current = $store->read($disk, $pointer);
            if (is_string($current) && hash('sha256', $current) === (string) $row->document_hash) {
                // A stored file that IS the version's bytes is verified, so
                // the recorded hash must say so. Two rows need the write, not
                // one: the legacy pointer whose `content_hash` was never
                // recorded (`unverified` forever otherwise) AND the row whose
                // recorded hash is present but WRONG — a stale value left by
                // a repair, which the Time Machine reports as `mismatch`
                // forever while the file on disk is provably correct. The
                // condition is therefore "differs from the version's hash",
                // never "is empty": the second row is the one an operator
                // actually runs this command for.
                if (! $dryRun && (string) $row->content_hash !== (string) $row->document_hash) {
                    // The same integrity-only write as the identical re-ingest
                    // (DocumentIngestor::recordContentHashIfDiffers): bound to
                    // the row's own tenant (R30). A row that is no longer the
                    // one read is reported, not silently skipped (R4).
                    if ($row->updateUnscopedWithinOwnTenant(['content_hash' => (string) $row->document_hash]) === 0) {
                        $this->line("  #{$row->id} {$row->source_path}: verified, but the content_hash could not be recorded (row changed underneath)");
                    } else {
                        $row->content_hash = (string) $row->document_hash;
                    }
                }
                // A verified artifact is the precondition of the row's
                // retention contract, whichever run stored it: a
                // `markdown_only` row whose finalization failed before (or
                // whose original came back) is repaired here too, best
                // effort, exactly as after a write. `--dry-run` never drops:
                // the gate is a disk write.
                if (! $dryRun) {
                    try {
                        if ($this->finalizeSourceRetentionUnderReservation($ingestor, $row, $disk, $prefix, $pointer)) {
                            $this->originalsDropped++;
                            $this->line("  #{$row->id} {$row->source_path}: already_stored (original dropped: markdown_only)");
                        }
                    } catch (\Throwable $e) {
                        $this->line("  #{$row->id} {$row->source_path}: already_stored (retention not finalized: {$e->getMessage()})");
                    }
                }

                return 'already_stored';
            }
            $this->line("  #{$row->id} {$row->source_path}: artifact pointer set but the file is ".($current === null ? 'missing' : 'corrupt').'; repairing');
        }
        try {
            $sourcePath = KbPath::normalize((string) $row->source_path);
            $fullPath = $prefix === '' ? $sourcePath : KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            $this->line("  #{$row->id} {$row->source_path}: source_missing (un-normalizable path)");

            return 'source_missing';
        }
        // The probe and the read are the disk's answers, not the row's:
        // an adapter that throws (a lost mount, a bucket that refuses) is
        // `disk_unavailable` for this row — never `source_missing` (the
        // source may well be there) nor `conversion_failed` (nothing was
        // converted), and never an unhandled crash mid-corpus (R14).
        try {
            $sourceExists = $storage->exists($fullPath);
            $bytes = $sourceExists ? $storage->get($fullPath) : null;
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$sourcePath}: disk_unavailable (disk [{$disk}] refused the read: {$e->getMessage()})");

            return 'disk_unavailable';
        }
        if (! $sourceExists) {
            $this->line("  #{$row->id} {$sourcePath}: source_missing");

            return 'source_missing';
        }
        if (! is_string($bytes) || $bytes === '') {
            // `exists()` said yes, `get()` said nothing (an adapter that
            // refuses the read without `throw`, a zero-byte object). The empty
            // case is the same refusal as the other entry points
            // (`kb:ingest`): reconverting nothing would write an empty
            // artifact and report it as a completed repair, so the row is
            // reported unreadable instead (R14).
            $this->line("  #{$row->id} {$sourcePath}: disk_unavailable (disk [{$disk}] returned no bytes for a source it reports as present)");

            return 'disk_unavailable';
        }

        try {
            // `--dry-run` never spends: the marker rides the source metadata
            // so `OcrService::isDryRun()` short-circuits the driver, the
            // `.ocr/` writes and the metering (a recorded run is still
            // read back, read-only, and verified; a row that would need a
            // fresh OCR run is reported, not converted).
            // The reconversion runs under the ROW's retention contract, never
            // the knob of the day: `OcrService::retentionModeOf()` reads the
            // stamp to decide figure output and run reuse, so a `full_copy` /
            // `markdown_only` version backfilled after the global mode moved
            // to `reference_only` reads its recorded run back and reproduces
            // the recorded Markdown instead of spending a new run whose hash
            // could never match.
            $converted = $registry->resolveConverter((string) $row->mime_type)->convert(new SourceDocument(
                sourcePath: $sourcePath,
                mimeType: (string) $row->mime_type,
                bytes: $bytes,
                externalUrl: null,
                externalId: null,
                connectorType: 'local',
                metadata: array_merge(['disk' => $disk, 'prefix' => $prefix, 'source_retention' => $rowMode], $dryRun ? ['dry_run' => true] : []),
            ));
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed ({$e->getMessage()})");

            return 'conversion_failed';
        }
        $ocrMeta = is_array($converted->extractionMeta['ocr'] ?? null) ? $converted->extractionMeta['ocr'] : [];
        // A recorded run read back in dry-run (`reused`) carries the real
        // Markdown and is verified like any other; only a PREVIEW (no run to
        // read back, none started) cannot be.
        if ($dryRun && ($ocrMeta['dry_run'] ?? false) === true && ($ocrMeta['reused'] ?? false) !== true) {
            $this->line("  #{$row->id} {$sourcePath}: would need an OCR run to verify; not run in dry-run (ocr_unverified)");

            return 'ocr_unverified';
        }

        $hash = hash('sha256', $converted->markdown);
        if ($hash !== (string) $row->document_hash) {
            $this->line("  #{$row->id} {$sourcePath}: hash_mismatch (reconversion differs from the recorded version; nothing written)");

            return 'hash_mismatch';
        }

        // The row's RECORDED prefix composes the path: one that cannot form an
        // artifact root (a traversal, the reserved `.artifacts` segment) is
        // this row's failure, reported — never an abort that loses the counts
        // of the rows already repaired (R14).
        try {
            $final = $store->pathFor($tenant, (string) $row->project_key, $sourcePath, (string) $row->version_hash, $prefix);
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed (the recorded prefix cannot form an artifact root: {$e->getMessage()})");

            return 'conversion_failed';
        }
        if ($dryRun) {
            $this->line("  #{$row->id} {$sourcePath}: would write {$final}");

            return 'written';
        }
        // Pointer first, bytes second — the same order as ingest (row commits
        // with the path, then the move): the orphan sweep only deletes files
        // no row points at, so a file published before its pointer would be
        // inside that window. On failure the pointer STAYS: it names the
        // version's bytes and the file is simply not there yet — the
        // `missing` state the next run repairs. Restoring the previous
        // pointer would race a concurrent repair of the same version (its
        // publish lands between this attempt's failure and the restore, and
        // the healthy file becomes an orphan): a pointer is never rolled
        // back, only repaired forward.
        // Bound to the row's own tenant (R30) and checked (R4): a row hard-deleted
        // between the chunk read and this write takes no pointer, so nothing
        // is published for it — bytes no row points at would be an orphan
        // reported as a repair.
        if ($row->updateUnscopedWithinOwnTenant(['markdown_path' => $final, 'content_hash' => $hash]) === 0) {
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed (the row changed underneath; nothing written)");

            return 'conversion_failed';
        }
        $row->markdown_path = $final;
        $row->content_hash = $hash;
        try {
            // Under the path's lock, re-checking the row (the same publish an
            // ingest does): a failed publish discards its temp, never left
            // for the age sweep.
            $published = $ingestor->publishArtifactForRow($disk, $store->writeTemp($disk, $final, $converted->markdown), $final, (int) $row->id, $tenant);
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed (could not publish: {$e->getMessage()}; the pointer is kept as `missing` for the next run)");

            return 'conversion_failed';
        }
        if (! $published) {
            $this->line("  #{$row->id} {$sourcePath}: conversion_failed (the row changed underneath; nothing written)");

            return 'conversion_failed';
        }
        // ADR 0030 §3 — the row's retention contract is applied once its
        // artifact is on disk and verified, exactly as after a fresh ingest:
        // for a `markdown_only` row the original is dropped under the storage
        // key's lock when no other referencing row still requires it, and the
        // rows are stamped `source_dropped`. A repair that stopped short of
        // this would leave the advertised retention transition half done.
        // The artifact IS written whatever happens next: a retention tail
        // that fails (a removed disk, a lock store outage) is reported on the
        // row's line and the run goes on — never a stack trace mid-corpus
        // that loses the counts of the rows already repaired (R14).
        try {
            $dropped = $this->finalizeSourceRetentionUnderReservation($ingestor, $row, $disk, $prefix, $final);
        } catch (\Throwable $e) {
            $this->line("  #{$row->id} {$sourcePath}: written {$final} (retention not finalized: {$e->getMessage()})");

            return 'written';
        }
        if ($dropped) {
            $this->originalsDropped++;
        }
        $this->line("  #{$row->id} {$sourcePath}: written {$final}".($dropped ? ' (original dropped: markdown_only)' : ''));

        return 'written';
    }

    /**
     * v8.36 — the round-34/36 acquire-and-hold pattern, applied to retention
     * finalization: `DocumentIngestor::finalizeSourceRetention()` already
     * serializes against a CONCURRENT INGEST'S ROW COMMIT (its own internal
     * `SourceKeyLock`), but says nothing about a fresh ingest that is still
     * READING/CONVERTING the same source — the exact half `SourceInFlight`
     * exists to cover (its own docblock: "a job reads the source and
     * converts it ... in that window the file has no row and no holder").
     * Reserved here, same as every other deleting consumer (`DocumentDeleter`,
     * `PruneOrphanFilesCommand`), so a fresh ingest reading this very file
     * blocks the drop instead of racing it.
     */
    private function finalizeSourceRetentionUnderReservation(DocumentIngestor $ingestor, KnowledgeDocument $row, string $disk, string $prefix, string $final): bool
    {
        if (! ConversionArtifactStore::cacheStoreCanLock()) {
            // No mutex at all: finalizeSourceRetention()'s own storage-key
            // lock is the only guard left (R43), exactly as before this fix.
            return $ingestor->finalizeSourceRetention($row, $disk, $final);
        }
        try {
            $sourcePath = KbPath::normalize((string) $row->source_path);
            $fullPath = $prefix === '' ? $sourcePath : KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException) {
            // An un-normalizable path: finalizeSourceRetention() resolves the
            // SAME path internally and returns false for the same reason, so
            // calling it unprotected here changes nothing about its outcome.
            return $ingestor->finalizeSourceRetention($row, $disk, $final);
        }
        $reservation = SourceInFlight::acquireForRemoval($disk, $fullPath);
        if ($reservation === null) {
            // An ingest holds it right now: the retention drop is deferred,
            // never raced. The next run retries it once the ingest finishes.
            $this->line("  #{$row->id} {$row->source_path}: retention not finalized (an ingest is reading this source right now; retried on the next run)");

            return false;
        }
        try {
            return $ingestor->finalizeSourceRetention($row, $disk, $final);
        } finally {
            $reservation->release();
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function report(array $counts, bool $dryRun): void
    {
        // Additive, printed only when non-zero (like `ocr_unverified`):
        // `disk_unavailable` (rows on a disk this deployment cannot resolve
        // or reach — a configuration / infrastructure condition) and
        // `originals_dropped` (originals the
        // run removed under a `markdown_only` contract — a destructive
        // outcome that must be visible in the one line operators read).
        $this->info(sprintf(
            'already_stored=%d written=%d intentionally_missing=%d source_missing=%d hash_mismatch=%d conversion_failed=%d%s%s%s%s',
            $counts['already_stored'],
            $counts['written'],
            $counts['intentionally_missing'],
            $counts['source_missing'],
            $counts['hash_mismatch'],
            $counts['conversion_failed'],
            $counts['ocr_unverified'] > 0 ? " ocr_unverified={$counts['ocr_unverified']}" : '',
            $counts['disk_unavailable'] > 0 ? " disk_unavailable={$counts['disk_unavailable']}" : '',
            $this->originalsDropped > 0 ? " originals_dropped={$this->originalsDropped}" : '',
            $dryRun ? ' (dry-run)' : '',
        ));
    }
}
