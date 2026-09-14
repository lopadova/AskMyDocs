<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KbCanonicalAudit;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Ocr\OcrService;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.36 / ADR 0030 §3 + §8 — `kb:prune-archived-versions` removes the
 * artifact with each pruned row and sweeps temp leftovers + orphans;
 * `kb:artifacts-backfill` reports every state and writes only on a hash match.
 */
final class ArtifactsRetentionCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
    }

    private function row(int $n, string $status, ?string $artifact = null, string $path = 'docs/dec.md', ?string $markdownOnDisk = null): KnowledgeDocument
    {
        $markdown = $markdownOnDisk ?? "# Doc\n\nversion {$n}\n";
        $hash = hash('sha256', $markdown);
        $attributes = [
            'project_key' => 'eng', 'source_path' => $path, 'source_type' => 'markdown', 'title' => "v{$n}",
            'mime_type' => 'text/markdown', 'language' => 'it', 'access_scope' => 'internal', 'status' => $status,
            'document_hash' => $hash, 'version_hash' => $hash, 'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now()->subDays(100 - $n),
        ];
        if ($artifact !== null) {
            $store = app(ConversionArtifactStore::class);
            $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', $path, $hash);
            $store->publish('kb', $store->writeTemp('kb', $final, $artifact), $final);
            $attributes['markdown_path'] = $final;
            $attributes['content_hash'] = $hash;
        }
        $doc = KnowledgeDocument::create($attributes);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id, 'project_key' => 'eng', 'chunk_order' => 0,
            'chunk_hash' => hash('sha256', "v{$n}chunk"), 'heading_path' => 'D', 'chunk_text' => "body {$n}", 'metadata' => [],
        ]);

        return $doc;
    }

    public function test_prune_removes_the_artifact_of_each_pruned_version_and_keeps_the_others(): void
    {
        $this->row(9, 'active', 'live');
        $kept = $this->row(8, 'archived', 'kept');
        $pruned1 = $this->row(2, 'archived', 'old 2');
        $pruned2 = $this->row(1, 'archived', 'old 1');

        $this->artisan('kb:prune-archived-versions', ['--keep' => 1])->assertExitCode(0);

        Storage::disk('kb')->assertExists((string) $kept->markdown_path);
        Storage::disk('kb')->assertMissing((string) $pruned1->markdown_path);
        Storage::disk('kb')->assertMissing((string) $pruned2->markdown_path);
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $pruned1->id]);
    }

    public function test_prune_purges_a_pruned_versions_ocr_run_only_when_no_remaining_row_references_it(): void
    {
        $shared = str_repeat('a', 64);
        $unique = str_repeat('b', 64);
        $withRun = fn (string $run): array => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $run]]];
        $live = $this->row(9, 'active');
        $live->update(['metadata' => $withRun($shared)]);
        $pruned1 = $this->row(2, 'archived');
        $pruned1->update(['metadata' => $withRun($shared)]);   // same run as the live row: stays
        $pruned2 = $this->row(1, 'archived');
        $pruned2->update(['metadata' => $withRun($unique)]);   // only this row: goes
        Storage::disk('kb')->put("docs/dec.md.ocr/{$shared}/result.json", '{}');
        Storage::disk('kb')->put("docs/dec.md.ocr/{$unique}/result.json", '{}');

        // Past the in-flight grace (ADR 0029 §6): the runs are not reservations any more.
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-archived-versions', ['--keep' => 0])->assertExitCode(0);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $pruned1->id]);
        Storage::disk('kb')->assertExists("docs/dec.md.ocr/{$shared}/result.json");
        Storage::disk('kb')->assertMissing("docs/dec.md.ocr/{$unique}/result.json");
    }

    /**
     * ADR 0029 §6 / ADR 0030 §8 — a run inside the in-flight grace is a
     * reservation the prune never purges; the row it belonged to is gone, so
     * it is now a stale run inside a live tree, which `kb:prune-orphan-files`
     * removes once aged — through the same deleter gate the prune asked.
     */
    public function test_prune_keeps_an_in_flight_run_and_the_orphan_sweep_removes_it_once_aged(): void
    {
        $shared = str_repeat('c', 64);
        $unique = str_repeat('d', 64);
        $withRun = fn (string $run): array => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $run]]];
        $this->row(9, 'active')->update(['metadata' => $withRun($shared)]);
        $pruned = $this->row(1, 'archived');
        $pruned->update(['metadata' => $withRun($unique)]);
        Storage::disk('kb')->put('docs/dec.md', '# live source');
        Storage::disk('kb')->put("docs/dec.md.ocr/{$shared}/result.json", '{}');
        Storage::disk('kb')->put("docs/dec.md.ocr/{$unique}/result.json", '{}');

        $this->artisan('kb:prune-archived-versions', ['--keep' => 0])->assertExitCode(0);
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $pruned->id]);
        Storage::disk('kb')->assertExists("docs/dec.md.ocr/{$unique}/result.json"); // in flight: kept

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('stale_runs=1 runs_purged=0 runs_in_flight=1')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists("docs/dec.md.ocr/{$unique}/result.json");

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('stale_runs=1 runs_purged=1 runs_in_flight=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertMissing("docs/dec.md.ocr/{$unique}/result.json");
        Storage::disk('kb')->assertExists("docs/dec.md.ocr/{$shared}/result.json");
    }

    public function test_prune_sweeps_stale_temps_and_orphans_but_never_a_referenced_or_trashed_rows_artifact(): void
    {
        $live = $this->row(9, 'active', 'live');
        $trashed = $this->row(8, 'archived', 'trashed');
        $trashed->delete(); // soft-deleted rows still own their artifact (R2)
        $store = app(ConversionArtifactStore::class);
        $orphan = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/gone.md', str_repeat('b', 64));
        $store->publish('kb', $store->writeTemp('kb', $orphan, 'nobody points here'), $orphan);
        $staleTmp = $orphan.'.dead-writer.tmp';
        Storage::disk('kb')->put($staleTmp, 'half written');
        touch(Storage::disk('kb')->path($staleTmp), time() - 7200);
        $freshTmp = $store->writeTemp('kb', $orphan, 'in flight');

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_temps_swept=1 artifact_temps_failed=0 artifact_orphans_removed=1 artifact_orphans_failed=0')
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists((string) $live->markdown_path);
        Storage::disk('kb')->assertExists((string) $trashed->markdown_path);
        Storage::disk('kb')->assertMissing($orphan);
        Storage::disk('kb')->assertMissing($staleTmp);
        Storage::disk('kb')->assertExists($freshTmp);
    }

    /**
     * An orphan candidate that a row takes BETWEEN the sweep's snapshot and
     * the removal (an identical ingest recreating the content-addressed
     * path) is kept by the gate's locked re-check, counted as
     * `artifact_orphans_kept`, never deleted under the new row — on the real
     * run and in the dry-run preview alike.
     */
    public function test_prune_keeps_an_orphan_candidate_a_row_took_after_the_snapshot_and_counts_it(): void
    {
        $this->app->bind(\App\Services\Kb\DocumentDeleter::class, \Tests\Fixtures\Kb\RaceInsertingDeleter::class);
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        $raced = $store->pathFor($tenant, 'eng', 'docs/raced.md', str_repeat('d', 64));
        $store->publish('kb', $store->writeTemp('kb', $raced, 'a row takes this path meanwhile'), $raced);
        $orphan = $store->pathFor($tenant, 'eng', 'docs/gone.md', str_repeat('e', 64));
        $store->publish('kb', $store->writeTemp('kb', $orphan, 'nobody points here'), $orphan);
        $takePath = function (string $disk, string $path): void {
            if (KnowledgeDocument::withoutGlobalScopes()->where('markdown_path', $path)->exists()) {
                return;
            }
            $this->row(5, 'archived', null, basename(dirname($path, 1), '.versions'))->forceFill(['markdown_path' => $path])->save();
        };
        try {
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$beforeGate = static function (string $disk, string $path) use ($raced, $takePath): void {
                if ($path === $raced) {
                    $takePath($disk, $path);
                }
            };

            $this->artisan('kb:prune-archived-versions')
                ->expectsOutputToContain('artifact_orphans_removed=1 artifact_orphans_failed=0 artifact_orphans_kept=1')
                ->assertExitCode(0);
            Storage::disk('kb')->assertExists($raced);
            Storage::disk('kb')->assertMissing($orphan);

            // The preview asks the gate's question too: a candidate a row took
            // since the snapshot is reported kept, and nothing is deleted.
            $late = $store->pathFor($tenant, 'eng', 'docs/late.md', str_repeat('f', 64));
            $store->publish('kb', $store->writeTemp('kb', $late, 'taken during the preview'), $late);
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$beforeGate = static function (string $disk, string $path) use ($late, $takePath): void {
                if ($path === $late) {
                    $takePath($disk, $path);
                }
            };
            $this->artisan('kb:prune-archived-versions', ['--dry-run' => true])
                ->expectsOutputToContain('artifact_orphans_removed=0 artifact_orphans_failed=0 artifact_orphans_kept=1 (dry-run)')
                ->assertExitCode(0);
            Storage::disk('kb')->assertExists($late);
        } finally {
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$beforeGate = null;
        }
    }

    /**
     * ADR 0030 §3 — the gate's lock has a TTL and no renewal: a re-check that
     * outlived it refuses the delete (`failed`, exit non-zero) instead of
     * removing the artifact under whoever holds the path now.
     */
    public function test_prune_refuses_an_orphan_removal_whose_path_lock_lapsed_during_the_re_check(): void
    {
        $this->app->bind(\App\Services\Kb\DocumentDeleter::class, \Tests\Fixtures\Kb\RaceInsertingDeleter::class);
        $store = app(ConversionArtifactStore::class);
        $orphan = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/gone.md', str_repeat('e', 64));
        $store->publish('kb', $store->writeTemp('kb', $orphan, 'nobody points here'), $orphan);
        try {
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$insideGate = static function (string $disk, string $path): void {
                Cache::lock('kb:artifact:'.$disk.':'.sha1($path))->forceRelease(); // the TTL lapsed mid-section
            };

            $this->artisan('kb:prune-archived-versions')
                ->expectsOutputToContain('could not remove orphan artifact [kb]')
                ->expectsOutputToContain('artifact_orphans_removed=0 artifact_orphans_failed=1')
                ->assertExitCode(1);
        } finally {
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$insideGate = null;
        }
        Storage::disk('kb')->assertExists($orphan);
    }

    /**
     * A row whose recorded disk is unusable (a JSON null, an empty string) is
     * a legacy, ambiguous reference for the snapshot AND the gate alike
     * (StorageNamespace): its artifact is never an orphan candidate on any
     * disk, so nothing is counted and nothing is deleted under it.
     */
    public function test_prune_never_treats_a_row_with_a_null_or_empty_disk_as_recorded_elsewhere(): void
    {
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        $nullDisk = $store->pathFor($tenant, 'eng', 'docs/null-disk.md', str_repeat('d', 64));
        $store->publish('kb', $store->writeTemp('kb', $nullDisk, 'null disk'), $nullDisk);
        $this->row(5, 'archived', null, 'docs/null-disk.md')->forceFill(['markdown_path' => $nullDisk, 'metadata' => ['disk' => null, 'prefix' => '']])->save();
        $emptyDisk = $store->pathFor($tenant, 'eng', 'docs/empty-disk.md', str_repeat('e', 64));
        $store->publish('kb', $store->writeTemp('kb', $emptyDisk, 'empty disk'), $emptyDisk);
        $this->row(6, 'archived', null, 'docs/empty-disk.md')->forceFill(['markdown_path' => $emptyDisk, 'metadata' => ['disk' => '', 'prefix' => '']])->save();

        // Neither is a candidate at all: nothing removed and nothing KEPT by
        // the gate either (the old "recorded, elsewhere" reading would have
        // reported them as two kept candidates).
        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_orphans_removed=0 artifact_orphans_failed=0')
            ->doesntExpectOutputToContain('artifact_orphans_kept=')
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists($nullDisk);
        Storage::disk('kb')->assertExists($emptyDisk);
    }

    /** R43 — with the flag OFF the prune still removes pruned rows' artifacts and keeps referenced ones. */
    public function test_prune_off_still_removes_pruned_artifacts_and_keeps_referenced_ones(): void
    {
        $this->row(9, 'active', 'live');
        $kept = $this->row(8, 'archived', 'kept');
        $pruned = $this->row(1, 'archived', 'old');
        config(['kb.conversion_artifacts.enabled' => false]);

        $this->artisan('kb:prune-archived-versions', ['--keep' => 1])->assertExitCode(0);

        Storage::disk('kb')->assertExists((string) $kept->markdown_path);
        Storage::disk('kb')->assertMissing((string) $pruned->markdown_path);
    }

    public function test_prune_dry_run_reports_and_deletes_nothing(): void
    {
        $store = app(ConversionArtifactStore::class);
        $orphan = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/gone.md', str_repeat('c', 64));
        $store->publish('kb', $store->writeTemp('kb', $orphan, 'orphan'), $orphan);

        $this->artisan('kb:prune-archived-versions', ['--dry-run' => true])
            ->expectsOutputToContain('artifact_orphans_removed=1 artifact_orphans_failed=0 (dry-run)')
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists($orphan);
    }

    public function test_backfill_writes_only_on_a_hash_match_and_reports_every_state(): void
    {
        $tenant = app(TenantContext::class)->current();
        // match: the source on disk converts to exactly the recorded hash
        Storage::disk('kb')->put('docs/match.md', "# Doc\n\nversion 1\n");
        $match = $this->row(1, 'active', null, 'docs/match.md');
        // mismatch: the file on disk changed since the row was recorded
        Storage::disk('kb')->put('docs/drift.md', "# Doc\n\nsomething else\n");
        $drift = $this->row(2, 'active', null, 'docs/drift.md');
        // source gone
        $gone = $this->row(3, 'active', null, 'docs/gone.md');
        // already has a VERIFIED artifact (readable, hashes to document_hash): nothing to do
        $has = $this->row(4, 'active', "# Doc\n\nversion 4\n", 'docs/has.md');
        // archived: not live, not a candidate
        Storage::disk('kb')->put('docs/old.md', "# Doc\n\nversion 5\n");
        $this->row(5, 'archived', null, 'docs/old.md');
        // a pointer is not an artifact: the file behind it is corrupt (a
        // publish that failed after commit, a later corruption) → repaired
        Storage::disk('kb')->put('docs/corrupt.md', "# Doc\n\nversion 6\n");
        $corrupt = $this->row(6, 'active', 'not the recorded bytes', 'docs/corrupt.md');

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant, '--dry-run' => true])
            ->expectsOutputToContain('already_stored=1 written=2 intentionally_missing=0 source_missing=1 hash_mismatch=1 conversion_failed=0 (dry-run)')
            ->assertExitCode(1); // the preview predicts the real run's exit: a missing source is an observation, not an action
        $this->assertNull($match->fresh()->markdown_path);
        $this->assertSame('not the recorded bytes', Storage::disk('kb')->get((string) $corrupt->markdown_path));

        // The real run settled every row but one (`source_missing`): a
        // partial failure exits non-zero (R14); `hash_mismatch` alone is
        // informational.
        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('already_stored=1 written=2 intentionally_missing=0 source_missing=1 hash_mismatch=1 conversion_failed=0')
            ->assertExitCode(1);
        $this->assertSame("# Doc\n\nversion 6\n", Storage::disk('kb')->get((string) $corrupt->fresh()->markdown_path));

        $written = $match->fresh();
        $this->assertNotNull($written->markdown_path);
        $this->assertSame($written->document_hash, $written->content_hash);
        $this->assertSame("# Doc\n\nversion 1\n", Storage::disk('kb')->get((string) $written->markdown_path));
        $this->assertNull($drift->fresh()->markdown_path);
        $this->assertNull($gone->fresh()->markdown_path);
        $this->assertSame($has->markdown_path, $has->fresh()->markdown_path);
    }

    /**
     * ADR 0030 §3 — the retention contract is the ROW's (`metadata.source_retention`,
     * a row without the stamp predates v8.36 and counts as `full_copy`), never
     * the configured mode of the day: a history ingested under `full_copy` is
     * still backfilled after the knob moved to `reference_only`, and a row
     * ingested under `reference_only` gets nothing whatever the knob says now.
     */
    public function test_backfill_judges_each_row_on_its_own_retention_contract_and_refuses_a_blank_tenant(): void
    {
        Storage::disk('kb')->put('docs/ref.md', "# Doc\n\nversion 1\n");
        $stamped = $this->row(1, 'active', null, 'docs/ref.md');
        $stamped->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'source_retention' => 'reference_only']]);
        Storage::disk('kb')->put('docs/legacy.md', "# Doc\n\nversion 2\n");
        $legacy = $this->row(2, 'active', null, 'docs/legacy.md'); // no stamp: full_copy
        config(['kb.source_retention.mode' => 'reference_only']); // the knob of the day decides nothing

        $this->artisan('kb:artifacts-backfill', ['--tenant' => app(TenantContext::class)->current()])
            ->expectsOutputToContain('already_stored=0 written=1 intentionally_missing=1')
            ->assertExitCode(0);
        $this->assertNull($stamped->fresh()->markdown_path);
        $this->assertNotNull($legacy->fresh()->markdown_path);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => ''])->assertExitCode(1);

        config(['kb.conversion_artifacts.enabled' => false, 'kb.source_retention.mode' => 'full_copy']);
        $this->artisan('kb:artifacts-backfill', ['--tenant' => app(TenantContext::class)->current()])
            ->expectsOutputToContain('KB_CONVERSION_ARTIFACTS_ENABLED=false')
            ->assertExitCode(1);
    }
    /**
     * ADR 0030 §3 — artifact disks are independent storage objects: a row
     * whose RECORDED disk is another one references another file with the
     * same path and does not keep this disk's orphan alive; a row that never
     * recorded its disk protects the path wherever the sweep looks (fail
     * closed, as for source files).
     */
    public function test_prune_sweep_judges_artifact_references_by_the_rows_recorded_disk(): void
    {
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        $otherDiskPath = $store->pathFor($tenant, 'eng', 'docs/other.md', str_repeat('d', 64));
        $store->publish('kb', $store->writeTemp('kb', $otherDiskPath, 'on kb, referenced only on kb-archive'), $otherDiskPath);
        $onOtherDisk = $this->row(1, 'active', null, 'docs/other.md');
        $onOtherDisk->update(['markdown_path' => $otherDiskPath, 'metadata' => ['disk' => 'kb-archive', 'prefix' => '']]);
        $legacyPath = $store->pathFor($tenant, 'eng', 'docs/legacy.md', str_repeat('e', 64));
        $store->publish('kb', $store->writeTemp('kb', $legacyPath, 'referenced by a row without a disk'), $legacyPath);
        $legacy = $this->row(2, 'active', null, 'docs/legacy.md');
        $legacy->update(['markdown_path' => $legacyPath, 'metadata' => []]);

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_orphans_removed=1 artifact_orphans_failed=0')
            ->assertExitCode(0);

        Storage::disk('kb')->assertMissing($otherDiskPath);
        Storage::disk('kb')->assertExists($legacyPath);
    }

    /**
     * R21 — the surplus is SELECTED as archived, but a Time Machine restore
     * can activate a row between that read and the delete: every candidate
     * is re-read and locked in the deleting transaction and pruned only if
     * it is still archived. Simulated here by activating the oldest candidate
     * the moment the batch hydrates it (the `retrieved` model event), i.e.
     * after the SELECT and before the lock. `lockForUpdate()` is a no-op on
     * SQLite, so this asserts the re-check; the serialization itself is
     * pgsql's `SELECT … FOR UPDATE` inside the same transaction. The listener
     * is registered on the per-test application's dispatcher (Testbench
     * rebuilds the app, and `DatabaseServiceProvider::boot()` re-binds
     * `Model::setEventDispatcher()`, for every test), so it never outlives
     * this test.
     */
    public function test_prune_never_deletes_a_version_restored_after_the_batch_was_selected(): void
    {
        config(['kb.versioning.keep_archived' => 1]);
        $this->row(1, 'archived');
        $restored = $this->row(2, 'archived');
        $this->row(3, 'archived');
        $this->row(4, 'active');
        $flipped = false;
        KnowledgeDocument::retrieved(static function (KnowledgeDocument $model) use ($restored, &$flipped): void {
            if ($flipped || (int) $model->id !== (int) $restored->id || $model->status !== 'archived') {
                return;
            }
            $flipped = true;
            // The concurrent restore: activates the row (query builder, no events) once the batch has read it.
            \Illuminate\Support\Facades\DB::table('knowledge_documents')->where('id', $restored->id)->update(['status' => 'active']);
        });

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('archived_versions_pruned=1 artifacts_removed=0 artifacts_absent=0 artifacts_failed=0 ocr_runs_purged=0 ocr_runs_kept=0 ocr_failed=0 restored_meanwhile=1')
            ->assertExitCode(0);

        $this->assertTrue($flipped, 'the simulated restore ran');
        $this->assertSame('active', KnowledgeDocument::withoutGlobalScopes()->whereKey($restored->id)->value('status'), 'the restored version survives the prune');
        $this->assertSame(3, KnowledgeDocument::withoutGlobalScopes()->count());
    }

    /** ADR 0030 §8 — the sweep covers every artifact namespace the corpus records, not only the configured disk. */
    public function test_prune_sweeps_every_recorded_artifact_disk(): void
    {
        Storage::fake('kb2');
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        // A row that recorded its artifact on the second disk keeps it alive there …
        $kept = $store->pathFor($tenant, 'eng', 'docs/second.md', str_repeat('f', 64));
        $store->publish('kb2', $store->writeTemp('kb2', $kept, 'kept on kb2'), $kept);
        $row = $this->row(1, 'active', null, 'docs/second.md');
        $row->update(['markdown_path' => $kept, 'content_hash' => hash('sha256', 'kept on kb2'), 'metadata' => ['disk' => 'kb2', 'prefix' => '']]);
        // … while an orphan and a stale temp on that same disk are swept, like on the configured one.
        $orphan = $store->pathFor($tenant, 'eng', 'docs/gone.md', str_repeat('a', 63).'b');
        $store->publish('kb2', $store->writeTemp('kb2', $orphan, 'orphan on kb2'), $orphan);
        // A dead writer's leftover: its lease has lapsed, only the file remains.
        $staleTemp = $kept.'.dead-writer.tmp';
        Storage::disk('kb2')->put($staleTemp, 'stale temp');
        touch(Storage::disk('kb2')->path($staleTemp), time() - 7200);
        // A row recording a disk this deployment does not know is reported, not swept (nothing to sweep it on).
        $unknown = $this->row(2, 'active', null, 'docs/unknown.md');
        $unknown->update(['markdown_path' => '.artifacts/x/eng/docs/unknown.md.versions/h.md', 'metadata' => ['disk' => 'nowhere', 'prefix' => '']]);

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('[kb] temps_swept=0 temps_failed=0 orphans_removed=0 orphans_failed=0')
            ->expectsOutputToContain('[kb2] temps_swept=1 temps_failed=0 orphans_removed=1 orphans_failed=0')
            ->expectsOutputToContain('rows record artifacts on disk [nowhere], which cannot be resolved here')
            ->expectsOutputToContain('artifact_temps_swept=1 artifact_temps_failed=0 artifact_orphans_removed=1 artifact_orphans_failed=0 artifact_namespaces_skipped=1')
            ->assertExitCode(0);

        Storage::disk('kb2')->assertExists($kept);
        Storage::disk('kb2')->assertMissing($orphan);
        Storage::disk('kb2')->assertMissing($staleTemp);
    }

    /**
     * ADR 0030 §8 — an OCR run directory is namespaced by disk, prefix,
     * source path and run: a row naming the same run under another prefix
     * references ANOTHER directory and does not keep this one alive; a
     * legacy row (no recorded disk) does, fail closed.
     */
    public function test_prune_purges_an_ocr_run_only_referenced_under_another_storage_namespace(): void
    {
        $shared = str_repeat('a', 64);
        $protected = str_repeat('b', 64);
        $this->row(9, 'active')->update(['metadata' => ['disk' => 'kb', 'prefix' => 'other', 'converter' => ['ocr' => ['run' => $shared]]]]);
        $this->row(2, 'archived')->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $shared]]]]);
        $this->row(1, 'archived')->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $protected]]]]);
        $this->row(8, 'active', null, 'docs/legacy.md')->update(['metadata' => ['converter' => ['ocr' => ['run' => $protected]]]]);
        $this->row(3, 'archived', null, 'docs/legacy.md')->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $protected]]]]);
        Storage::disk('kb')->put("docs/dec.md.ocr/{$shared}/result.json", '{}');
        Storage::disk('kb')->put("other/docs/dec.md.ocr/{$shared}/result.json", '{}');
        Storage::disk('kb')->put("docs/dec.md.ocr/{$protected}/result.json", '{}');
        Storage::disk('kb')->put("docs/legacy.md.ocr/{$protected}/result.json", '{}');

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-archived-versions', ['--keep' => 0])
            ->expectsOutputToContain('ocr_runs_purged=2 ocr_runs_kept=0 ocr_failed=0')
            ->assertExitCode(0);

        Storage::disk('kb')->assertMissing("docs/dec.md.ocr/{$shared}/result.json");   // the live row's run lives under `other/`
        Storage::disk('kb')->assertExists("other/docs/dec.md.ocr/{$shared}/result.json");
        Storage::disk('kb')->assertMissing("docs/dec.md.ocr/{$protected}/result.json");
        Storage::disk('kb')->assertExists("docs/legacy.md.ocr/{$protected}/result.json"); // legacy row: fail closed
    }

    /** A verified pointer whose `content_hash` was never recorded gets it from the backfill (not in dry-run), so the Time Machine can claim integrity. */
    public function test_backfill_records_the_content_hash_of_a_verified_legacy_pointer(): void
    {
        $tenant = app(TenantContext::class)->current();
        $legacy = $this->row(1, 'active', "# Doc\n\nversion 1\n", 'docs/legacy-pointer.md');
        $legacy->update(['content_hash' => null]);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant, '--dry-run' => true])
            ->expectsOutputToContain('already_stored=1 written=0')
            ->assertExitCode(0);
        $this->assertNull($legacy->fresh()->content_hash);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('already_stored=1 written=0')
            ->assertExitCode(0);
        $this->assertSame($legacy->document_hash, $legacy->fresh()->content_hash);
    }

    /**
     * `--dry-run` never spends: an OCR row whose recorded run can be read back
     * is verified from it (reported like any other row), while a row that
     * would need a fresh run is reported as `ocr_unverified`, not converted —
     * the driver is never called and no `.ocr/` run is written.
     */
    public function test_backfill_dry_run_verifies_from_a_recorded_ocr_run_and_never_spends(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.ocr.fake.pages' => [['markdown' => 'scanned text']]]);
        // The ingest below embeds: no provider leaves the test (R13).
        $cache = \Mockery::mock(\App\Services\Kb\EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new \App\Ai\EmbeddingsResponse(embeddings: array_map(fn () => array_fill(0, 8, 0.0), $texts), provider: 'fake', model: 'fake-8'),
        );
        $this->app->instance(\App\Services\Kb\EmbeddingCacheService::class, $cache);
        $tenant = app(TenantContext::class)->current();
        $png = (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true);
        Storage::disk('kb')->put('scans/recorded.png', $png);
        $recorded = app(\App\Services\Kb\DocumentIngestor::class)->ingest('eng', new \App\Services\Kb\Pipeline\SourceDocument(
            sourcePath: 'scans/recorded.png', mimeType: 'image/png', bytes: $png,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Recorded');
        $this->assertNotNull($recorded->markdown_path);
        Storage::disk('kb')->delete((string) $recorded->markdown_path); // the artifact is gone; the run is recorded
        // A scan nobody ever OCR'd: no run to read back.
        Storage::disk('kb')->put('scans/fresh.png', $png.'fresh');
        $fresh = $this->row(2, 'active', null, 'scans/fresh.png');
        $fresh->update(['mime_type' => 'image/png', 'source_type' => 'image']);
        $runsBefore = count(Storage::disk('kb')->allFiles('scans'));

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant, '--dry-run' => true])
            ->expectsOutputToContain('already_stored=0 written=1 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=0 ocr_unverified=1 (dry-run)')
            ->assertExitCode(0);

        Storage::disk('kb')->assertMissing((string) $recorded->markdown_path);
        $this->assertSame($runsBefore, count(Storage::disk('kb')->allFiles('scans')), 'a dry run writes no OCR run');
        Storage::disk('kb')->assertMissing('scans/fresh.png.ocr');
    }

    /**
     * ADR 0030 §3 — the backfill reconverts under the ROW's retention
     * contract, never the knob of the day: a version recorded with figures
     * and a reusable run (`full_copy` / `markdown_only`) is backfilled after
     * the global mode moved to `reference_only` by reading its recorded run
     * back — the same Markdown, the same hash, no new run spent. Under the
     * global mode the converter would disable figures and reuse, produce a
     * different Markdown and report a mismatch on a version that is intact.
     */
    public function test_backfill_reconverts_under_the_rows_retention_contract_not_the_current_mode(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.ocr.fake.pages' => [['markdown' => 'scanned text', 'confidence' => 0.9, 'figures' => 1]]]);
        $cache = \Mockery::mock(\App\Services\Kb\EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new \App\Ai\EmbeddingsResponse(embeddings: array_map(fn () => array_fill(0, 8, 0.0), $texts), provider: 'fake', model: 'fake-8'),
        );
        $this->app->instance(\App\Services\Kb\EmbeddingCacheService::class, $cache);
        $tenant = app(TenantContext::class)->current();
        $png = (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true);
        Storage::disk('kb')->put('scans/contract.png', $png);
        $recorded = app(\App\Services\Kb\DocumentIngestor::class)->ingest('eng', new \App\Services\Kb\Pipeline\SourceDocument(
            sourcePath: 'scans/contract.png', mimeType: 'image/png', bytes: $png,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Contract');
        $this->assertSame('full_copy', $recorded->metadata['source_retention']);
        $artifact = (string) $recorded->markdown_path;
        $this->assertStringContainsString('images/', (string) Storage::disk('kb')->get($artifact), 'the recorded version references its figure');
        Storage::disk('kb')->delete($artifact);
        $runsBefore = count(Storage::disk('kb')->allFiles('scans'));

        config(['kb.source_retention.mode' => 'reference_only']); // the knob of the day

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('already_stored=0 written=1 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=0')
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists($artifact);
        $this->assertSame((string) $recorded->document_hash, hash('sha256', (string) Storage::disk('kb')->get($artifact)));
        $this->assertSame($runsBefore, count(Storage::disk('kb')->allFiles('scans')), 'the recorded run is read back, not spent again');
        $this->assertSame('full_copy', $recorded->fresh()->metadata['source_retention'], 'the row keeps its own contract');
    }

    /** ADR 0030 §3 — a backfill write applies the row's retention contract: a `markdown_only` row's original goes through the same reference-aware gate as ingest. */
    public function test_backfill_applies_the_rows_retention_contract_after_a_write(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.ocr.fake.pages' => [['markdown' => 'scanned text']]]);
        $cache = \Mockery::mock(\App\Services\Kb\EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new \App\Ai\EmbeddingsResponse(embeddings: array_map(fn () => array_fill(0, 8, 0.0), $texts), provider: 'fake', model: 'fake-8'),
        );
        $this->app->instance(\App\Services\Kb\EmbeddingCacheService::class, $cache);
        $tenant = app(TenantContext::class)->current();
        $png = (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true);
        Storage::disk('kb')->put('scans/dropme.png', $png);
        config(['kb.source_retention.mode' => 'markdown_only']);
        $row = app(\App\Services\Kb\DocumentIngestor::class)->ingest('eng', new \App\Services\Kb\Pipeline\SourceDocument(
            sourcePath: 'scans/dropme.png', mimeType: 'image/png', bytes: $png,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Drop me');
        Storage::disk('kb')->assertMissing('scans/dropme.png');
        $artifact = (string) $row->markdown_path;
        // The artifact is lost and the original comes back (a restore from backup): the row is `markdown_only` with a full copy on disk again.
        Storage::disk('kb')->delete($artifact);
        Storage::disk('kb')->put('scans/dropme.png', $png);
        KnowledgeDocument::withoutGlobalScopes()->whereKey($row->id)->update(['metadata' => array_diff_key($row->fresh()->metadata, ['source_dropped' => true])]);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('(original dropped: markdown_only)')
            ->expectsOutputToContain('already_stored=0 written=1 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=0 originals_dropped=1')
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists($artifact);
        Storage::disk('kb')->assertMissing('scans/dropme.png');
        $this->assertTrue($row->fresh()->metadata['source_dropped']);
    }

    /** ADR 0030 §3 — a verified artifact already there is the same precondition as a fresh write: the row's `markdown_only` contract is finalized on `already_stored` too. */
    public function test_backfill_finalizes_the_rows_retention_contract_on_an_already_stored_artifact(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'fake', 'kb.ocr.fake.pages' => [['markdown' => 'scanned text']]]);
        $cache = \Mockery::mock(\App\Services\Kb\EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new \App\Ai\EmbeddingsResponse(embeddings: array_map(fn () => array_fill(0, 8, 0.0), $texts), provider: 'fake', model: 'fake-8'),
        );
        $this->app->instance(\App\Services\Kb\EmbeddingCacheService::class, $cache);
        $tenant = app(TenantContext::class)->current();
        $png = (string) base64_decode(\App\Services\Kb\Ocr\Drivers\FakeOcrDriver::PNG_1X1, true);
        Storage::disk('kb')->put('scans/back.png', $png);
        config(['kb.source_retention.mode' => 'markdown_only']);
        $row = app(\App\Services\Kb\DocumentIngestor::class)->ingest('eng', new \App\Services\Kb\Pipeline\SourceDocument(
            sourcePath: 'scans/back.png', mimeType: 'image/png', bytes: $png,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Back');
        Storage::disk('kb')->assertMissing('scans/back.png');
        // The original comes back (a restore from backup) while the verified artifact is still there.
        Storage::disk('kb')->put('scans/back.png', $png);
        KnowledgeDocument::withoutGlobalScopes()->whereKey($row->id)->update(['metadata' => array_diff_key($row->fresh()->metadata, ['source_dropped' => true])]);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant, '--dry-run' => true])
            ->expectsOutputToContain('already_stored=1 written=0')
            ->assertExitCode(0);
        Storage::disk('kb')->assertExists('scans/back.png'); // a dry run finalizes nothing

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('already_stored (original dropped: markdown_only)')
            ->expectsOutputToContain('already_stored=1 written=0 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=0 originals_dropped=1')
            ->assertExitCode(0);

        Storage::disk('kb')->assertMissing('scans/back.png');
        $this->assertTrue($row->fresh()->metadata['source_dropped']);
    }

    /** R14 — a row whose recorded disk cannot be resolved here is one reported row, never an abort that leaves the rest of the corpus unprocessed. */
    public function test_backfill_reports_a_row_on_an_unresolvable_disk_and_goes_on(): void
    {
        $tenant = app(TenantContext::class)->current();
        $lost = $this->row(1, 'active', null, 'docs/lost.md');
        $lost->update(['markdown_path' => '.artifacts/x/eng/docs/lost.md.versions/h.md', 'metadata' => ['disk' => 'nowhere', 'prefix' => '']]);
        Storage::disk('kb')->put('docs/fine.md', "# Doc\n\nversion 2\n");
        $fine = $this->row(2, 'active', null, 'docs/fine.md');

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('disk_unavailable (disk [nowhere] cannot be resolved here')
            ->expectsOutputToContain('already_stored=0 written=1 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=0 disk_unavailable=1')
            ->assertExitCode(1); // a row it could not reach is a partial failure (R14)

        $this->assertNotNull($fine->fresh()->markdown_path, 'the rows after the unresolvable one are still processed');
    }

    /** R14 — a disk that refuses the source probe (a lost mount, a bucket answering 5xx) is `disk_unavailable` for that row — never `source_missing`, never a crash mid-corpus. */
    public function test_backfill_reports_a_disk_that_refuses_the_source_probe_and_goes_on(): void
    {
        $tenant = app(TenantContext::class)->current();
        Storage::disk('kb')->put('docs/unreachable.md', "# Doc\n\nversion 1\n");
        $unreachable = $this->row(1, 'active', null, 'docs/unreachable.md');
        Storage::disk('kb')->put('docs/fine.md', "# Doc\n\nversion 2\n");
        $fine = $this->row(2, 'active', null, 'docs/fine.md');
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => false, static fn (string $path, string $operation): bool => $path === 'docs/unreachable.md');
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        try {
            $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
                ->expectsOutputToContain('docs/unreachable.md: disk_unavailable (disk [kb] refused the read')
                ->expectsOutputToContain('already_stored=0 written=1 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=0 disk_unavailable=1')
                ->assertExitCode(1); // a row it could not reach is a partial failure (R14)
        } finally {
            Storage::set('kb', $healthy);
        }

        $this->assertNull($unreachable->fresh()->markdown_path, 'nothing was written for the row the disk refused');
        $this->assertNotNull($fine->fresh()->markdown_path, 'the rows after the refused one are still processed');
    }

    /**
     * A temp a live writer holds the lease of is never swept, however old
     * (a slow transaction is not a dead writer): kept and reported
     * `in_flight`. Once the lease is gone — the writer died and its lease
     * expired — the age threshold takes it. Publishing releases the lease.
     */
    public function test_prune_keeps_a_leased_temp_however_old_and_sweeps_it_once_the_lease_is_gone(): void
    {
        $this->row(9, 'active', 'live');
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/slow.md', str_repeat('c', 64));
        $leased = $store->writeTemp('kb', $final, 'a slow transaction');
        touch(Storage::disk('kb')->path($leased), time() - 7200); // far past KB_CONVERSION_ARTIFACTS_TMP_MAX_AGE
        $this->assertTrue(ConversionArtifactStore::tempLeaseHeld('kb', $leased));

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_temps_swept=0 artifact_temps_failed=0 artifact_orphans_removed=0 artifact_orphans_failed=0 artifact_temps_in_flight=1')
            ->assertExitCode(0);
        Storage::disk('kb')->assertExists($leased);

        // The writer died: its lease lapses (forced here; the TTL does it in production).
        Cache::lock(ConversionArtifactStore::tempLeaseKey('kb', $leased))->forceRelease();
        $this->assertFalse(ConversionArtifactStore::tempLeaseHeld('kb', $leased));
        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_temps_swept=1 artifact_temps_failed=0 artifact_orphans_removed=0 artifact_orphans_failed=0')
            ->assertExitCode(0);
        Storage::disk('kb')->assertMissing($leased);

        // A publish gives the lease back; so does a discard.
        $published = $store->writeTemp('kb', $final, 'published');
        $store->publish('kb', $published, $final);
        $this->assertFalse(ConversionArtifactStore::tempLeaseHeld('kb', $published));
        $discarded = $store->writeTemp('kb', $final, 'discarded');
        $store->discardTemp('kb', $discarded);
        $this->assertFalse(ConversionArtifactStore::tempLeaseHeld('kb', $discarded));
        Storage::disk('kb')->assertMissing($discarded);
    }

    /** A lease length that is not a positive number of seconds is reported and replaced by the default, never a lease that expires at once. */
    public function test_a_non_positive_temp_lease_falls_back_to_the_default(): void
    {
        config(['kb.conversion_artifacts.tmp_lease_seconds' => 0]);
        $this->assertSame(ConversionArtifactStore::DEFAULT_TMP_LEASE_SECONDS, ConversionArtifactStore::tempLeaseSeconds());
        config(['kb.conversion_artifacts.tmp_lease_seconds' => 45]);
        $this->assertSame(45, ConversionArtifactStore::tempLeaseSeconds());
    }

    /**
     * The surplus of a family is chosen by recency with NULL `indexed_at`
     * LAST, as on the timeline: an archived row that never recorded when it
     * was indexed is the OLDEST, pruned first — never kept as the family's
     * newest while a dated version is pruned. SQLite happens to order NULLs
     * last under DESC on its own, so the outcome alone cannot fail here: the
     * ordering the command SENDS is asserted from the query log, which does
     * fail without the explicit `CASE` PostgreSQL needs.
     */
    public function test_prune_treats_an_archived_version_without_indexed_at_as_the_oldest(): void
    {
        config(['kb.versioning.keep_archived' => 1]);
        $this->row(9, 'active', 'live');
        $dated = $this->row(8, 'archived', 'dated');
        $undated = $this->row(7, 'archived', 'undated');
        \Illuminate\Support\Facades\DB::table('knowledge_documents')->where('id', $undated->id)->update(['indexed_at' => null]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('archived_versions_pruned=1 artifacts_removed=1')
            ->assertExitCode(0);
        $surplusQueries = array_values(array_filter(
            \Illuminate\Support\Facades\DB::getQueryLog(),
            static fn (array $q): bool => str_contains($q['query'], '"status" = ?') && in_array('archived', $q['bindings'], true) && str_contains($q['query'], 'order by') && str_contains($q['query'], 'offset'),
        ));
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertNotEmpty($surplusQueries, 'the surplus query was issued');
        foreach ($surplusQueries as $q) {
            $this->assertMatchesRegularExpression('/order by CASE WHEN indexed_at IS NULL THEN 1 ELSE 0 END, "indexed_at" desc, "id" desc/', $q['query'], 'NULL indexed_at sorts last on every driver, before the recency order');
        }

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $undated->id]);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $dated->id]);
        Storage::disk('kb')->assertExists((string) $dated->markdown_path);
    }

    /** The lease is the primary guard: one shorter than the age threshold is honoured but reported, once per process, not once per write. */
    public function test_a_temp_lease_shorter_than_the_age_threshold_is_reported_once(): void
    {
        config(['kb.conversion_artifacts.tmp_lease_seconds' => 100, 'kb.conversion_artifacts.tmp_max_age_seconds' => 3600]);
        \Illuminate\Support\Facades\Log::spy();

        $this->assertSame(100, ConversionArtifactStore::tempLeaseSeconds());
        $this->assertSame(100, ConversionArtifactStore::tempLeaseSeconds());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'shorter than tmp_max_age_seconds'));
    }

    /**
     * R43 / R14 — a cache store that cannot lock (apc, session, a custom
     * store) never turns the artifact write into an outage: the temp is
     * written unleased, the gap is reported once, and the age threshold
     * alone decides the sweep.
     */
    public function test_a_cache_store_without_locks_leaves_the_temp_unleased_and_the_age_threshold_in_charge(): void
    {
        Cache::extend('nolock', static fn ($app) => Cache::repository(new \Tests\Fixtures\Cache\NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);
        \Illuminate\Support\Facades\Log::spy();
        $this->row(9, 'active', 'live');
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/nolock.md', str_repeat('d', 64));

        $tmp = $store->writeTemp('kb', $final, 'written without a lease');

        Storage::disk('kb')->assertExists($tmp);
        $this->assertFalse(ConversionArtifactStore::tempLeaseHeld('kb', $tmp));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'cannot hold locks'));

        touch(Storage::disk('kb')->path($tmp), time() - 7200);
        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_temps_swept=1 artifact_temps_failed=0 artifact_orphans_removed=0 artifact_orphans_failed=0')
            ->assertExitCode(0);
        Storage::disk('kb')->assertMissing($tmp);
    }

    /**
     * R14 — without a lock-capable store the artifact PATH lock is
     * unavailable, and a publish or a removal without it would race every
     * other writer of the path: both are refused (the orphan is reported
     * `failed`, exit non-zero; a publish throws and discards its temp), never
     * run unguarded. Only the temp lease degrades to "unleased".
     */
    public function test_a_cache_store_without_locks_refuses_artifact_removal_and_publish(): void
    {
        Cache::extend('nolock', static fn ($app) => Cache::repository(new \Tests\Fixtures\Cache\NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        $orphan = $store->pathFor($tenant, 'eng', 'docs/gone.md', str_repeat('e', 64));
        Storage::disk('kb')->put($orphan, 'nobody points here');

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_orphans_removed=0 artifact_orphans_failed=1')
            ->assertExitCode(1);
        Storage::disk('kb')->assertExists($orphan);

        $final = $store->pathFor($tenant, 'eng', 'docs/unlocked.md', str_repeat('f', 64));
        $tmp = $store->writeTemp('kb', $final, 'written without a lease');
        $thrown = null;
        try {
            app(\App\Services\Kb\DocumentIngestor::class)->publishArtifactForRow('kb', $tmp, $final, 999999, $tenant);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'a publish without the path lock must be refused');
        $this->assertStringContainsString('cannot hold locks', $thrown->getMessage());
        Storage::disk('kb')->assertMissing($tmp);
        Storage::disk('kb')->assertMissing($final);
    }

    /** SEC-PATH-001 — a `.artifacts` root that is a symlink out of the disk is refused before it is probed or listed: a reported failed sweep, never an enumeration of the outside. */
    public function test_prune_refuses_a_symlinked_artifact_root_before_listing_it(): void
    {
        $outside = sys_get_temp_dir().'/askmydocs-outside-'.uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside.'/stray.md', 'outside the disk');
        $diskRoot = Storage::disk('kb')->path('');
        $this->assertTrue(symlink($outside, rtrim($diskRoot, '/').'/.artifacts'));

        try {
            $this->artisan('kb:prune-archived-versions')
                ->expectsOutputToContain('artifact_temps_swept=0 artifact_temps_failed=1 artifact_orphans_removed=0 artifact_orphans_failed=1')
                ->assertExitCode(1);
            $this->assertFileExists($outside.'/stray.md', 'nothing outside the disk was touched');
        } finally {
            unlink(rtrim($diskRoot, '/').'/.artifacts');
            unlink($outside.'/stray.md');
            rmdir($outside);
        }
    }

    /**
     * ADR 0030 §8 — the reference gate: an artifact a row still points at is
     * never removed, whoever asks. The hard delete of a row whose artifact
     * path a NEWER identical version recreated meanwhile keeps the file
     * (nothing of the deleted row remains there), and the prune reports the
     * same outcome as `artifacts_kept`.
     */
    public function test_the_reference_gate_keeps_an_artifact_a_newer_identical_version_points_at(): void
    {
        $pruned = $this->row(1, 'archived', 'shared bytes');
        $path = (string) $pruned->markdown_path;
        // The identical version recreated after the prune's snapshot: another row, the same content-addressed path.
        $recreated = $this->row(9, 'active');
        \Illuminate\Support\Facades\DB::table('knowledge_documents')->where('id', $recreated->id)->update(['markdown_path' => $path, 'content_hash' => $pruned->content_hash]);
        $deleter = app(\App\Services\Kb\DocumentDeleter::class);

        $this->assertSame(ConversionArtifactStore::KEPT, $deleter->removeArtifactIfUnreferenced('kb', $path));
        Storage::disk('kb')->assertExists($path);

        $outcome = $deleter->deleteRowsOnly($pruned);
        $this->assertTrue($outcome['artifact_deleted'], 'nothing of the deleted row remains at the path');
        Storage::disk('kb')->assertExists($path);

        // The prune: with keep=1 the two oldest archived versions go; the
        // live row points at the path of one of them (the recreated
        // identical version), so that artifact is kept and reported.
        $shared = $this->row(2, 'archived', 'old 2');
        $this->row(3, 'archived', 'old 3');
        $this->row(4, 'archived', 'old 4');
        \Illuminate\Support\Facades\DB::table('knowledge_documents')->where('id', $recreated->id)->update(['markdown_path' => $shared->markdown_path, 'content_hash' => $shared->content_hash]);
        config(['kb.versioning.keep_archived' => 1]);
        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('archived_versions_pruned=2 artifacts_removed=1 artifacts_absent=0 artifacts_failed=0 ocr_runs_purged=0 ocr_runs_kept=0 ocr_failed=0 artifacts_kept=1')
            ->assertExitCode(0);
        Storage::disk('kb')->assertExists((string) $shared->markdown_path);
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $shared->id]);
    }

    /** R14 / SEC-PATH-001 — a configured prefix carrying the reserved `.artifacts` segment is a reported failed sweep, never a root drawn one level up. */
    public function test_prune_reports_a_prefix_carrying_the_reserved_segment_as_a_failed_sweep(): void
    {
        config(['kb.sources.path_prefix' => 'a/.artifacts']);

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('reserved')
            ->expectsOutputToContain('artifact_temps_swept=0 artifact_temps_failed=1 artifact_orphans_removed=0 artifact_orphans_failed=1')
            ->assertExitCode(1);
    }

    /** A publish that fails leaves the pointer as the repairable `missing` state — never rolled back under a concurrent repair's feet — and the next run repairs it. */
    public function test_backfill_keeps_the_pointer_of_a_failed_publish_and_repairs_it_on_the_next_run(): void
    {
        $tenant = app(TenantContext::class)->current();
        Storage::disk('kb')->put('docs/refused.md', "# Doc\n\nversion 1\n");
        $row = $this->row(1, 'active', null, 'docs/refused.md');
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_ends_with($path, '.tmp'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('; the pointer is kept as `missing` for the next run)')
            ->expectsOutputToContain('already_stored=0 written=0 intentionally_missing=0 source_missing=0 hash_mismatch=0 conversion_failed=1')
            ->assertExitCode(1); // a refused publish is a partial failure (R14)
        $pointer = (string) $row->fresh()->markdown_path;
        $this->assertNotSame('', $pointer, 'the pointer names the version\'s bytes');
        $this->assertSame((string) $row->document_hash, $row->fresh()->content_hash);
        $this->assertSame('missing', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($row->fresh()));

        Storage::set('kb', $healthy);
        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('artifact pointer set but the file is missing; repairing')
            ->expectsOutputToContain('already_stored=0 written=1')
            ->assertExitCode(0);
        Storage::disk('kb')->assertExists($pointer);
        $this->assertSame('verified', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($row->fresh()));
    }

    /** R14 — a configured prefix that cannot form an artifact root is a reported failure, never an unhandled crash. */
    public function test_prune_reports_a_traversing_prefix_as_a_failed_sweep(): void
    {
        config(['kb.sources.path_prefix' => '../outside']);

        $this->artisan('kb:prune-archived-versions')
            ->expectsOutputToContain('artifact_temps_swept=0 artifact_temps_failed=1 artifact_orphans_removed=0 artifact_orphans_failed=1')
            ->assertExitCode(1);
    }

    /** A run reserved by a converter at this very moment is kept, never deleted under its feet. */
    public function test_prune_keeps_an_ocr_run_a_converter_holds_the_reservation_of(): void
    {
        $run = str_repeat('f', 64);
        $this->row(9, 'active');
        $this->row(1, 'archived')->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $run]]]]);
        Storage::disk('kb')->put('docs/dec.md', '# live source');
        Storage::disk('kb')->put("docs/dec.md.ocr/{$run}/result.json", '{}');
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();

        $reservation = Cache::lock(OcrService::runLockKey('kb', "docs/dec.md.ocr/{$run}"), 60);
        $this->assertTrue($reservation->get());
        try {
            $this->artisan('kb:prune-archived-versions', ['--keep' => 0])
                ->expectsOutputToContain('ocr_runs_purged=0 ocr_runs_kept=1 ocr_failed=0')
                ->assertExitCode(0);
        } finally {
            $reservation->release();
        }
        Storage::disk('kb')->assertExists("docs/dec.md.ocr/{$run}/result.json");

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('stale_runs=1 runs_purged=1 runs_in_flight=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertMissing("docs/dec.md.ocr/{$run}/result.json");
    }

    /**
     * The prune takes the deleter's row path: an archived canonical version
     * that still owns a graph node loses it and gets its deprecation audit
     * row, like every other hard delete.
     */
    public function test_prune_cascades_the_graph_and_writes_the_deprecation_audit_through_the_deleter(): void
    {
        $this->row(9, 'active');
        $old = $this->row(1, 'archived');
        $old->update(['is_canonical' => true, 'doc_id' => 'dec-old', 'slug' => 'dec-old', 'canonical_type' => 'decision', 'canonical_status' => 'accepted']);
        KbNode::create(['project_key' => 'eng', 'node_uid' => 'dec-old', 'node_type' => 'decision', 'label' => 'dec-old', 'source_doc_id' => 'dec-old', 'payload_json' => []]);

        $this->artisan('kb:prune-archived-versions', ['--keep' => 0])->assertExitCode(0);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $old->id]);
        $this->assertSame(0, KbNode::withoutGlobalScopes()->where('node_uid', 'dec-old')->count());
        $this->assertSame(1, KbCanonicalAudit::withoutGlobalScopes()->where('doc_id', 'dec-old')->where('event_type', 'deprecated')->count());
    }

    /**
     * R14 — a refused artifact delete is a failed cleanup, reported and
     * non-zero, never "pruned": here the file resolves outside the artifact
     * root through a planted symlink and the store refuses to touch it.
     */
    public function test_prune_reports_a_refused_artifact_delete_and_exits_non_zero(): void
    {
        $this->row(9, 'active');
        $pruned = $this->row(1, 'archived', 'old');
        $root = rtrim(Storage::disk('kb')->path(''), '/');
        $docsDir = $root.'/'.dirname(dirname((string) $pruned->markdown_path));
        $outside = sys_get_temp_dir().'/amd-outside-'.uniqid();
        rename($docsDir, $outside);
        symlink($outside, $docsDir);
        try {
            $this->artisan('kb:prune-archived-versions', ['--keep' => 0])
                ->expectsOutputToContain('artifacts_removed=0 artifacts_absent=0 artifacts_failed=1')
                ->assertExitCode(1);
            $this->assertDatabaseMissing('knowledge_documents', ['id' => $pruned->id]);
            $this->assertFileExists($outside.'/'.basename(dirname((string) $pruned->markdown_path)).'/'.basename((string) $pruned->markdown_path));
        } finally {
            unlink($docsDir);
            rename($outside, $docsDir);
        }
    }

    /**
     * The local adapter refuses to walk through a symbolic link under the
     * root (`SymbolicLinkEncountered`): the temp sweep reports a refused
     * enumeration as a failed sweep and the command exits non-zero — the
     * file behind the link is never touched.
     */
    public function test_prune_reports_a_refused_artifact_root_enumeration_as_a_failed_sweep(): void
    {
        $root = rtrim(Storage::disk('kb')->path(''), '/');
        mkdir($root.'/.artifacts', 0755, true);
        $outside = sys_get_temp_dir().'/amd-outside-'.uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside.'/dead.md.x.tmp', 'half written');
        touch($outside.'/dead.md.x.tmp', time() - 7200);
        symlink($outside, $root.'/.artifacts/link');
        try {
            $this->artisan('kb:prune-archived-versions')
                ->expectsOutputToContain('artifact_temps_swept=0 artifact_temps_failed=1')
                ->assertExitCode(1);
            $this->assertFileExists($outside.'/dead.md.x.tmp');
        } finally {
            unlink($root.'/.artifacts/link');
            unlink($outside.'/dead.md.x.tmp');
            rmdir($outside);
        }
    }
}
