<?php

namespace Tests\Feature\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrFigureStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PruneOrphanFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** A run key has the store's shape: the 64 hex chars of a SHA-256. */
    private const RUN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('kb.canonical_disk', 'kb');
        config()->set('kb.raw_disk', 'kb-raw');
        config()->set('kb.project_disks', []);
        config()->set('kb.deletion.soft_delete', true);
    }

    /**
     * A row records the storage namespace its file lives in (`metadata.disk`
     * / `metadata.prefix`, as the ingest job persists them): a test that
     * selects another disk or prefix seeds the row with THAT namespace.
     */
    private function seedDoc(string $sourcePath, string $versionHash, string $project = 'demo', string $disk = 'kb', string $prefix = ''): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => pathinfo($sourcePath, PATHINFO_FILENAME),
            'source_path' => $sourcePath,
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => $versionHash,
            'version_hash' => $versionHash,
            'metadata' => ['disk' => $disk, 'prefix' => $prefix],
            'indexed_at' => now(),
        ]);
    }

    /** SEC-PATH-001 — a traversing `KB_PATH_PREFIX` is refused before any walk or delete. */
    public function test_a_traversing_prefix_is_refused_before_any_scan(): void
    {
        config()->set('kb.sources.path_prefix', '../outside');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('KB_PATH_PREFIX cannot be used as a scan root')
            ->assertExitCode(1);
    }

    public function test_dry_run_lists_orphans_without_deleting(): void
    {
        Storage::fake('kb');

        // Five markdown files; only 3 have DB rows -> 2 orphans.
        Storage::disk('kb')->put('docs/a.md', 'a');
        Storage::disk('kb')->put('docs/b.md', 'b');
        Storage::disk('kb')->put('docs/c.md', 'c');
        Storage::disk('kb')->put('docs/orphan1.md', 'o1');
        Storage::disk('kb')->put('docs/orphan2.md', 'o2');
        // Two non-markdown files must be ignored entirely.
        Storage::disk('kb')->put('docs/readme.txt', 'ignore');
        Storage::disk('kb')->put('docs/logo.png', 'ignore');

        $this->seedDoc('docs/a.md', 'ha');
        $this->seedDoc('docs/b.md', 'hb');
        $this->seedDoc('docs/c.md', 'hc');

        $this->artisan('kb:prune-orphan-files', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN: 2 of 5 orphan file(s)')
            ->assertSuccessful();

        // Nothing was deleted.
        Storage::disk('kb')->assertExists('docs/a.md');
        Storage::disk('kb')->assertExists('docs/b.md');
        Storage::disk('kb')->assertExists('docs/c.md');
        Storage::disk('kb')->assertExists('docs/orphan1.md');
        Storage::disk('kb')->assertExists('docs/orphan2.md');
        Storage::disk('kb')->assertExists('docs/readme.txt');
        Storage::disk('kb')->assertExists('docs/logo.png');
    }

    public function test_normal_run_deletes_orphans_only(): void
    {
        Storage::fake('kb');

        Storage::disk('kb')->put('docs/kept.md', 'k');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        Storage::disk('kb')->put('docs/readme.txt', 'keep');

        $this->seedDoc('docs/kept.md', 'hk');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertExists('docs/kept.md');
        Storage::disk('kb')->assertMissing('docs/orphan.md');
        // Non-markdown files are never touched.
        Storage::disk('kb')->assertExists('docs/readme.txt');
    }

    /**
     * ADR 0030 §3 — the deletion re-checks the references before deleting: a
     * row that took the path between the sweep's snapshot and the delete (an
     * ingest committing meanwhile) keeps its file, reported `kept_meanwhile`,
     * never deleted under the new row. (The storage key lock the gate takes
     * while artifacts are on is proven by the two tests right after this
     * one: a key a writer holds is kept as in flight, a key whose lock
     * lapsed is refused.)
     */
    public function test_an_orphan_a_row_took_after_the_snapshot_is_kept_and_counted(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/raced.md', 'r');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        $this->app->bind(\App\Services\Kb\DocumentDeleter::class, \Tests\Fixtures\Kb\RaceInsertingDeleter::class);
        try {
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$beforeSourceGate = function (string $disk, string $fullPath, string $sourcePath): void {
                if ($sourcePath === 'docs/raced.md' && KnowledgeDocument::withoutGlobalScopes()->where('source_path', $sourcePath)->doesntExist()) {
                    $this->seedDoc('docs/raced.md', 'hr'); // an ingest commits the row meanwhile
                }
            };

            $this->artisan('kb:prune-orphan-files')
                ->expectsOutputToContain('kept (a row references it now, or a writer holds its key): docs/raced.md')
                ->expectsOutputToContain('scanned=2 orphans=2 deleted=1 failed=0 orphan_ocr_kept=0 dangling_ocr=0 purged=0 in_flight=0 ocr_failed=0 stale_runs=0 runs_purged=0 runs_in_flight=0 runs_failed=0 kept_meanwhile=1')
                ->assertSuccessful();
        } finally {
            \Tests\Fixtures\Kb\RaceInsertingDeleter::$beforeSourceGate = null;
        }

        Storage::disk('kb')->assertExists('docs/raced.md');
        Storage::disk('kb')->assertMissing('docs/orphan.md');
    }

    /**
     * ADR 0030 §3 / R43 (ON path) — while conversion artifacts are on, the
     * deletion runs under the storage key's lock: a key a writer holds right
     * now (a row commit, a `markdown_only` drop in progress) is in flight,
     * kept and counted `kept_meanwhile`, never deleted under the writer; the
     * other orphan, whose key is free, is deleted as usual.
     */
    public function test_with_artifacts_on_an_orphan_whose_storage_key_a_writer_holds_is_kept_as_in_flight(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/inflight.md', 'x');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        $writer = \Illuminate\Support\Facades\Cache::lock('kb:source:kb:'.sha1('docs/inflight.md'), 60);
        $this->assertTrue($writer->get(), 'a concurrent writer holds the key');
        try {
            $this->artisan('kb:prune-orphan-files')
                ->expectsOutputToContain('kept (a row references it now, or a writer holds its key): docs/inflight.md')
                ->expectsOutputToContain('scanned=2 orphans=2 deleted=1 failed=0 orphan_ocr_kept=0 dangling_ocr=0 purged=0 in_flight=0 ocr_failed=0 stale_runs=0 runs_purged=0 runs_in_flight=0 runs_failed=0 kept_meanwhile=1')
                ->assertSuccessful();
        } finally {
            $writer->release();
        }

        Storage::disk('kb')->assertExists('docs/inflight.md');
        Storage::disk('kb')->assertMissing('docs/orphan.md');

        // Once the key is free the same file is an orphan again and goes.
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=1 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertMissing('docs/inflight.md');
    }

    /**
     * ADR 0030 §3 — the delete runs only while the lock is still owned: a
     * storage-key lock whose TTL lapsed during the re-check (the store now
     * reports another owner) refuses the deletion — `failed`, exit non-zero
     * — never a delete under whoever holds the key now. The lapsed lock is
     * injected for that key only; the other orphan's key is real and free.
     */
    public function test_with_artifacts_on_an_orphan_whose_storage_key_lock_lapsed_is_refused_and_reported_failed(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/lapsed.md', 'x');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        $key = 'kb:source:kb:'.sha1('docs/lapsed.md');
        $store = \Illuminate\Support\Facades\Cache::store();
        $lapsed = new class($key, 60) extends \Illuminate\Cache\Lock
        {
            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease()
            {
            }

            protected function getCurrentOwner()
            {
                return 'another-writer'; // the TTL lapsed and someone else took the key
            }
        };
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(fn (string $name, int $seconds = 0, $owner = null) => $name === $key ? $lapsed : $store->lock($name, $seconds, $owner));

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('failed to delete: docs/lapsed.md')
            ->expectsOutputToContain('scanned=2 orphans=2 deleted=1 failed=1')
            ->assertExitCode(1);

        Storage::disk('kb')->assertExists('docs/lapsed.md'); // refused, never deleted past a lapsed lock
        Storage::disk('kb')->assertMissing('docs/orphan.md');
    }

    /**
     * v8.36 / ADR 0029 — an orphan source (a failed first ingest) may have
     * left an OCR run beside it; the sweep removes both, and never treats
     * the run's own files as orphan candidates.
     */
    public function test_deleting_an_orphan_source_purges_the_ocr_run_beside_it(): void
    {
        Storage::fake('kb');

        Storage::disk('kb')->put('docs/kept.md', 'k');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        Storage::disk('kb')->put('docs/orphan.md.ocr/'.self::RUN.'/images/fig-1-1.png', 'figure');
        Storage::disk('kb')->put('docs/orphan.md.ocr/'.self::RUN.'/result.json', '{}');
        Storage::disk('kb')->put('docs/kept.md.ocr/fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210/notes.md', 'not a source');

        $kept = $this->seedDoc('docs/kept.md', 'hk');
        // The live row names its run: a referenced run is never a stale-run candidate.
        $kept->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => str_repeat('fedcba9876543210', 4)]]]]);

        // Past the in-flight grace (ADR 0029 §6): the run is not a reservation any more.
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertMissing('docs/orphan.md');
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/orphan.md.ocr'), 'the orphan run goes with its source');
        Storage::disk('kb')->assertExists('docs/kept.md');
        Storage::disk('kb')->assertExists('docs/kept.md.ocr/fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210/notes.md'); // a run beside a live source is neither a candidate nor purged
    }

    /**
     * ADR 0030 §3 — the OCR run purge asserts its reservation right before
     * the removal: the in-flight scan reads the directory, and a TTL that
     * lapsed across it means another converter may hold the run now. The
     * purge is then deferred (kept, the next sweep decides), never a tree
     * deleted under the converter that reserved it.
     */
    public function test_a_run_purge_whose_reservation_lapsed_during_the_scan_is_deferred(): void
    {
        Storage::fake('kb');
        $run = self::RUN;
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        Storage::disk('kb')->put("docs/orphan.md.ocr/{$run}/result.json", '{}');
        $runDir = "docs/orphan.md.ocr/{$run}";
        $key = \App\Services\Kb\Ocr\OcrService::runLockKey('kb', $runDir);
        $store = \Illuminate\Support\Facades\Cache::store();
        $lapsed = new class($key, 60) extends \Illuminate\Cache\Lock
        {
            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease() {}

            protected function getCurrentOwner()
            {
                return 'another-converter'; // the reservation lapsed during the scan
            }
        };
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(fn (string $name, int $seconds = 0, $owner = null) => $name === $key ? $lapsed : $store->lock($name, $seconds, $owner));

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();

        $this->assertFalse(app(OcrFigureStore::class)->purgeRun('kb', 'docs/orphan.md', '', $run), 'the purge is deferred, not performed');
        Storage::disk('kb')->assertExists("{$runDir}/result.json");
    }

    /**
     * ADR 0030 §8 — the stale-run gate judges every candidate `(source, run)`
     * pair in one batched query: two sources sharing one run KEY are two
     * candidates (a reference to `a.md`'s run never protects `b.md`'s), a row
     * naming the run under ANOTHER prefix references another directory, and a
     * legacy row (no recorded disk) protects its run, fail closed.
     */
    public function test_the_stale_run_gate_judges_each_source_run_pair_and_its_namespace(): void
    {
        Storage::fake('kb');
        $run = self::RUN;
        foreach (['a', 'b', 'c', 'd'] as $name) {
            Storage::disk('kb')->put("docs/{$name}.md", $name);
            Storage::disk('kb')->put("docs/{$name}.md.ocr/{$run}/result.json", '{}');
        }
        // a.md: a live row names the run under THIS namespace → referenced.
        $this->seedDoc('docs/a.md', 'ha')->update(['metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['ocr' => ['run' => $run]]]]);
        // b.md: a live row, but it does not name the run → the run beside b.md is stale (the same key as a.md's does not protect it).
        $this->seedDoc('docs/b.md', 'hb');
        // c.md: a live row in THIS namespace keeps the source; the only row naming the run records
        // another prefix → it references another directory, so c.md's run here is stale.
        $this->seedDoc('docs/c.md', 'hc');
        $this->seedDoc('docs/c.md', 'hc2', prefix: 'elsewhere')->update(['status' => 'archived', 'metadata' => ['disk' => 'kb', 'prefix' => 'elsewhere', 'converter' => ['ocr' => ['run' => $run]]]]);
        // d.md: a legacy row (no recorded disk) names the run → fail closed, kept.
        $this->seedDoc('docs/d.md', 'hd')->update(['metadata' => ['converter' => ['ocr' => ['run' => $run]]]]);

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('orphans=0 deleted=0 failed=0 orphan_ocr_kept=0 dangling_ocr=0 purged=0 in_flight=0 ocr_failed=0 stale_runs=2 runs_purged=2 runs_in_flight=0 runs_failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertExists("docs/a.md.ocr/{$run}/result.json");
        Storage::disk('kb')->assertMissing("docs/b.md.ocr/{$run}/result.json");
        Storage::disk('kb')->assertMissing("docs/c.md.ocr/{$run}/result.json");
        Storage::disk('kb')->assertExists("docs/d.md.ocr/{$run}/result.json");
    }

    /**
     * A `.ocr` tree whose source is gone from the disk and from every row
     * (a hard delete that kept an in-flight run) is swept here — grace-aware:
     * a run recorded inside the in-flight window is kept, an aged one goes.
     * The source-row check is cross-tenant and includes trashed rows (the
     * deleter's R30 exception), and a tree beside a still-referenced key is
     * never a candidate.
     */
    /**
     * An orphan source goes; the `.ocr/` run beside it recorded inside the
     * in-flight grace is KEPT (its row may be about to commit) and reported
     * as such — never counted as a clean sweep, never as a failure.
     */
    public function test_an_orphan_source_is_deleted_and_its_in_flight_ocr_run_is_kept_and_reported(): void
    {
        Storage::fake('kb');
        $run = str_repeat('0123456789abcdef', 4);
        Storage::disk('kb')->put('docs/orphan.md', '# orphan');
        Storage::disk('kb')->put("docs/orphan.md.ocr/{$run}/result.json", '{}');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('kept (in flight): docs/orphan.md.ocr')
            ->expectsOutputToContain('orphans=1 deleted=1 failed=0 orphan_ocr_kept=1')
            ->assertExitCode(0);

        Storage::disk('kb')->assertMissing('docs/orphan.md');
        $this->assertTrue(Storage::disk('kb')->directoryExists("docs/orphan.md.ocr/{$run}"));
    }

    /**
     * The reference gate resolves each row's RECORDED disk + prefix: a row
     * on another disk that shares the logical `source_path` does not keep an
     * orphaned tree on this disk alive.
     */
    public function test_a_row_on_another_disk_does_not_protect_a_dangling_ocr_tree_on_this_one(): void
    {
        Storage::fake('kb');
        Storage::fake('kb-other');
        $run = str_repeat('abcdef0123456789', 4);
        Storage::disk('kb')->put("docs/elsewhere.md.ocr/{$run}/result.json", '{}');
        // Same logical path, recorded on ANOTHER disk: not a reference to this key.
        $other = $this->seedDoc('docs/elsewhere.md', 'he');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($other->id)->update(['metadata' => json_encode(['disk' => 'kb-other', 'prefix' => ''])]);

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=1')
            ->assertSuccessful();
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/elsewhere.md.ocr'));
    }

    /**
     * A row ingested before the storage namespace was persisted (no
     * `metadata.disk`) protects the file on its path wherever a deleting
     * consumer looks — the orphan sweep and the deleter's public reference
     * gate alike — so deletion fails closed and never guesses a disk that
     * would make every legacy row on a per-project disk a stranger to its
     * own file.
     */
    public function test_a_legacy_row_without_a_recorded_namespace_protects_its_file_on_a_per_project_disk(): void
    {
        config()->set('kb.project_disks', ['hr-portal' => 'kb-hr']);
        Storage::fake('kb-hr');
        Storage::fake('kb');
        Storage::disk('kb-hr')->put('docs/legacy-null.md', 'a');
        Storage::disk('kb-hr')->put('docs/legacy-prefix-only.md', 'b');
        Storage::disk('kb-hr')->put('docs/orphan.md', 'o');
        $null = $this->seedDoc('docs/legacy-null.md', 'h1', 'hr-portal');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($null->id)->update(['metadata' => null]);
        $prefixOnly = $this->seedDoc('docs/legacy-prefix-only.md', 'h2', 'hr-portal');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($prefixOnly->id)->update(['metadata' => json_encode(['prefix' => ''])]);

        $this->artisan('kb:prune-orphan-files', ['--project' => 'hr-portal'])
            ->expectsOutputToContain('scanned=3 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb-hr')->assertExists('docs/legacy-null.md');
        Storage::disk('kb-hr')->assertExists('docs/legacy-prefix-only.md');
        Storage::disk('kb-hr')->assertMissing('docs/orphan.md');

        // The deleter's public reference gate (cursor-loaded rows, the path
        // the dangling-tree sweep and the connector bridge delete through)
        // fails closed on a legacy row too: it references the object on
        // whichever disk the caller asks about.
        $deleter = app(\App\Services\Kb\DocumentDeleter::class);
        $this->assertSame((int) $null->id, $deleter->documentReferencingStorageKey('kb-hr', 'docs/legacy-null.md', 'docs/legacy-null.md'));
        $this->assertSame((int) $null->id, $deleter->documentReferencingStorageKey('kb', 'docs/legacy-null.md', 'docs/legacy-null.md'));
    }

    /** The dangling-tree sweep never purges the run beside a live legacy row on a per-project disk. */
    public function test_a_legacy_row_keeps_the_ocr_tree_beside_it_on_a_per_project_disk(): void
    {
        config()->set('kb.project_disks', ['hr-portal' => 'kb-hr']);
        Storage::fake('kb-hr');
        Storage::fake('kb');
        $run = str_repeat('abcdef0123456789', 4);
        Storage::disk('kb-hr')->put("docs/legacy.md.ocr/{$run}/result.json", '{}');
        $legacy = $this->seedDoc('docs/legacy.md', 'hl', 'hr-portal');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($legacy->id)->update(['metadata' => null]);

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        // Nothing to sweep at all: the tree is referenced, no source file is listed.
        $this->artisan('kb:prune-orphan-files', ['--project' => 'hr-portal'])
            ->expectsOutputToContain('No source files found on disk [kb-hr]')
            ->assertSuccessful();
        $this->assertTrue(Storage::disk('kb-hr')->directoryExists('docs/legacy.md.ocr'));
    }

    /**
     * The sweep decides over the WHOLE table: run by a user whose
     * AccessScopeScope hides other projects' rows (the admin command runner
     * executes it under the caller), those projects' files must never be
     * classified as orphans — R30/R33, the same posture as the dangling-tree
     * sweep.
     */
    public function test_the_sweep_ignores_the_callers_project_scope_when_deciding_orphans(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);
        config()->set('kb.project_isolation.enabled', true);
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/mine.md', 'm');
        Storage::disk('kb')->put('docs/theirs.md', 't');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        $this->seedDoc('docs/mine.md', 'hm', 'demo');
        $this->seedDoc('docs/theirs.md', 'ht', 'other-project');

        $viewer = \App\Models\User::create(['name' => 'viewer', 'email' => 'viewer-'.uniqid().'@demo.local', 'password' => \Illuminate\Support\Facades\Hash::make('secret123')]);
        $viewer->assignRole('viewer');
        \App\Models\ProjectMembership::create(['user_id' => $viewer->id, 'project_key' => 'demo', 'role' => 'member']);
        $this->actingAs($viewer);
        $this->assertSame(1, KnowledgeDocument::query()->count(), 'the scope hides the other project for this caller');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=3 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertExists('docs/mine.md');
        Storage::disk('kb')->assertExists('docs/theirs.md');
        Storage::disk('kb')->assertMissing('docs/orphan.md');
    }

    /**
     * The tree-key parser always terminates: a `.ocr/` segment whose suffix is
     * not the store's layout (an ordinary directory that happens to end in
     * `.ocr`, nested ones, a tree inside such a directory) is skipped by
     * searching strictly before it, and the answer is the innermost real tree
     * or null.
     */
    public function test_the_ocr_tree_key_parser_terminates_on_every_shape_of_path(): void
    {
        $run = str_repeat('abcdef0123456789', 4);
        $this->assertNull(\App\Console\Commands\PruneOrphanFilesCommand::ocrTreeSourceKey('docs/archive.ocr/manual.md'));
        $this->assertNull(\App\Console\Commands\PruneOrphanFilesCommand::ocrTreeSourceKey('a.ocr/b.ocr/c.ocr/x.md'));
        $this->assertNull(\App\Console\Commands\PruneOrphanFilesCommand::ocrTreeSourceKey('docs/archive.ocr/'.$run.'/notes.txt'));
        $this->assertSame('docs/archive.ocr/scan.png', \App\Console\Commands\PruneOrphanFilesCommand::ocrTreeSourceKey('docs/archive.ocr/scan.png.ocr/'.$run.'/result.json'));
        $this->assertSame('docs/scan.png', \App\Console\Commands\PruneOrphanFilesCommand::ocrTreeSourceKey('docs/scan.png.ocr/'.$run.'/images/fig-1-1.png'));
    }

    /**
     * The orphan test is the same physical test the dangling-tree sweep
     * applies: a row carrying the same logical path on ANOTHER disk or under
     * ANOTHER prefix references another object, so the file in THIS
     * namespace (and the `.ocr/` tree beside it) is an orphan here.
     */
    public function test_a_row_on_another_disk_or_prefix_does_not_protect_a_source_file_in_this_namespace(): void
    {
        Storage::fake('kb');
        Storage::fake('kb-other');
        $run = str_repeat('abcdef0123456789', 4);
        Storage::disk('kb')->put('docs/elsewhere.md', 'e');
        Storage::disk('kb')->put("docs/elsewhere.md.ocr/{$run}/result.json", '{}');
        Storage::disk('kb')->put('docs/archived.md', 'a');
        Storage::disk('kb')->put('docs/here.md', 'h');
        $other = $this->seedDoc('docs/elsewhere.md', 'he');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($other->id)->update(['metadata' => json_encode(['disk' => 'kb-other', 'prefix' => ''])]);
        $archived = $this->seedDoc('docs/archived.md', 'ha');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($archived->id)->update(['metadata' => json_encode(['disk' => 'kb', 'prefix' => 'archive'])]);
        $this->seedDoc('docs/here.md', 'hh');

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN: 2 of 3 orphan file(s)')
            ->assertSuccessful();

        $this->artisan('kb:prune-orphan-files')
            ->assertSuccessful();
        Storage::disk('kb')->assertMissing('docs/elsewhere.md');
        Storage::disk('kb')->assertMissing('docs/archived.md');
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/elsewhere.md.ocr'), 'the OCR tree beside the orphan goes with it');
        Storage::disk('kb')->assertExists('docs/here.md');
    }

    /**
     * v8.36 / ADR 0029 — with OCR on, an image is a source: an orphan scan
     * and the `.ocr/` tree beside it are swept like an orphan Markdown file.
     * With OCR off images are not sources and are never touched (R43).
     */
    public function test_orphan_images_are_swept_only_while_ocr_is_on(): void
    {
        Storage::fake('kb');
        $run = str_repeat('0123456789abcdef', 4);
        Storage::disk('kb')->put('scans/orphan.png', 'PNG');
        Storage::disk('kb')->put("scans/orphan.png.ocr/{$run}/images/fig-1-1.png", 'figure');
        Storage::disk('kb')->put("scans/orphan.png.ocr/{$run}/result.json", '{}');
        Storage::disk('kb')->put('scans/kept.png', 'PNG');
        $this->seedDoc('scans/kept.png', 'hk');
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();

        config(['kb.ocr.enabled' => false]);
        $this->artisan('kb:prune-orphan-files')->assertSuccessful();
        Storage::disk('kb')->assertExists('scans/orphan.png');

        config(['kb.ocr.enabled' => true]);
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('orphans=1 deleted=1 failed=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertMissing('scans/orphan.png');
        $this->assertFalse(Storage::disk('kb')->directoryExists('scans/orphan.png.ocr'));
        Storage::disk('kb')->assertExists('scans/kept.png');
    }

    /**
     * A directory that merely ends in `.ocr` is not a generated tree: the
     * sweep identifies a tree by the store's run layout (`{sha256}/result.json`,
     * `{sha256}/images/…`), so a legitimate source under such a directory is
     * never resolved to a `docs/archive` key nobody references — which would
     * have let the purge remove the whole source subtree.
     */
    public function test_a_source_inside_a_directory_named_dot_ocr_is_not_mistaken_for_a_dangling_tree(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/archive.ocr/manual.md', '# manual');
        Storage::disk('kb')->put('docs/archive.ocr/notes/readme.md', '# readme');
        $this->seedDoc('docs/archive.ocr/manual.md', 'hm');
        $this->seedDoc('docs/archive.ocr/notes/readme.md', 'hr');
        // The real tree of a source that itself lives under that directory
        // resolves to the SOURCE, and is dangling only once the source is
        // gone from the disk and from every row.
        Storage::disk('kb')->put('docs/archive.ocr/gone.md.ocr/'.self::RUN.'/result.json', '{}');
        // A run directory that does not have the store's shape is not a run.
        Storage::disk('kb')->put('docs/other.ocr/short/result.json', '{}');
        $this->seedDoc('docs/other.ocr/short/result.json', 'hs');

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files', ['--dry-run' => true])
            ->expectsOutputToContain('docs/archive.ocr/gone.md.ocr')
            ->expectsOutputToContain('0 of 0 orphan file(s), 1 dangling OCR tree(s) and 0 stale OCR run(s)')
            ->assertSuccessful();

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=1 in_flight=0 ocr_failed=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists('docs/archive.ocr/manual.md');
        Storage::disk('kb')->assertExists('docs/archive.ocr/notes/readme.md');
        Storage::disk('kb')->assertExists('docs/other.ocr/short/result.json');
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/archive.ocr/gone.md.ocr'));
    }

    public function test_a_dangling_ocr_tree_is_swept_only_once_it_has_aged_past_the_in_flight_grace(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/gone.md.ocr/'.self::RUN.'/images/fig-1-1.png', 'figure');
        Storage::disk('kb')->put('docs/gone.md.ocr/'.self::RUN.'/result.json', '{}');
        // Source gone from the disk but a soft-deleted row of ANOTHER tenant
        // still references the key: the tree is that row's, not dangling.
        Storage::disk('kb')->put('docs/theirs.md.ocr/fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210/result.json', '{}');
        $theirs = $this->seedDoc('docs/theirs.md', 'ht');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($theirs->id)->update(['tenant_id' => 'other-tenant', 'deleted_at' => now()]);

        $this->artisan('kb:prune-orphan-files', ['--dry-run' => true])
            ->expectsOutputToContain('docs/gone.md.ocr')
            ->expectsOutputToContain('0 of 0 orphan file(s), 1 dangling OCR tree(s) and 0 stale OCR run(s)')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists('docs/gone.md.ocr/'.self::RUN.'/result.json');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=0 in_flight=1 ocr_failed=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists('docs/gone.md.ocr/'.self::RUN.'/result.json');

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=1 in_flight=0 ocr_failed=0')
            ->assertSuccessful();
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/gone.md.ocr'));
        Storage::disk('kb')->assertExists('docs/theirs.md.ocr/fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210/result.json');
    }

    public function test_soft_deleted_documents_protect_their_file_from_being_flagged_orphan(): void
    {
        Storage::fake('kb');

        Storage::disk('kb')->put('docs/soft.md', 's');
        Storage::disk('kb')->put('docs/real-orphan.md', 'r');

        $soft = $this->seedDoc('docs/soft.md', 'hs');
        $soft->delete();

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        // The soft-deleted doc's file must survive — prune-deleted is the
        // command that will eventually remove it once retention expires.
        Storage::disk('kb')->assertExists('docs/soft.md');
        Storage::disk('kb')->assertMissing('docs/real-orphan.md');
    }

    /** R14 — a disk that refuses the probe of a tree's source keeps the tree (fail closed) and exits non-zero, never a crash after the walk. */
    public function test_a_refused_source_probe_keeps_the_ocr_tree_and_is_reported(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/gone.md.ocr/'.self::RUN.'/result.json', '{}');
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => false, static fn (string $path, string $operation): bool => $path === 'docs/gone.md');
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        try {
            $this->artisan('kb:prune-orphan-files')
                ->expectsOutputToContain('could not probe the source of OCR tree docs/gone.md.ocr')
                ->expectsOutputToContain('tree_probe_failed=1')
                ->assertExitCode(1);
        } finally {
            Storage::set('kb', $healthy);
        }
        Storage::disk('kb')->assertExists('docs/gone.md.ocr/'.self::RUN.'/result.json');
    }

    /** R14 — a walk the disk refuses (a planted symbolic link, a bucket page that fails) is a reported failed sweep; nothing is deleted. */
    public function test_a_refused_walk_is_reported_and_deletes_nothing(): void
    {
        $driver = Mockery::mock(\League\Flysystem\FilesystemOperator::class);
        $driver->shouldReceive('listContents')->with('', true)->andThrow(\League\Flysystem\SymbolicLinkEncountered::atLocation('docs/link'));
        $fake = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $fake->shouldReceive('getDriver')->andReturn($driver);
        $fake->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->with('kb')->andReturn($fake);

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('Could not complete the scan of disk [kb] (League\\Flysystem\\SymbolicLinkEncountered)')
            ->assertExitCode(1);
    }

    public function test_delete_failure_is_surfaced_as_nonzero_exit(): void
    {
        // Don't use Storage::fake — it always succeeds on delete. Use a
        // Mockery spy for the whole disk instead. R4: never swallow failures.
        // The command walks the disk lazily through the Flysystem driver's
        // listing (R3), so that is the seam the fake answers on.
        $driver = Mockery::mock(\League\Flysystem\FilesystemOperator::class);
        $driver->shouldReceive('listContents')
            ->with('', true)
            ->andReturn(new \League\Flysystem\DirectoryListing([new \League\Flysystem\FileAttributes('docs/orphan.md')]));
        $fake = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $fake->shouldReceive('getDriver')->andReturn($driver);
        // The gate probes the file before deleting it; the branch under test is the disk REFUSING the delete.
        $fake->shouldReceive('exists')
            ->with('docs/orphan.md')
            ->andReturn(true);
        $fake->shouldReceive('delete')
            ->with('docs/orphan.md')
            ->once()
            ->andReturn(false);

        Storage::shouldReceive('disk')
            ->with('kb')
            ->andReturn($fake);

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('failed to delete: docs/orphan.md')
            ->expectsOutputToContain('scanned=1 orphans=1 deleted=0 failed=1')
            ->assertExitCode(1);
    }

    public function test_project_option_routes_through_per_project_disk(): void
    {
        config()->set('kb.project_disks', ['hr-portal' => 'kb-hr']);

        Storage::fake('kb-hr');
        // Also fake the default disk to prove we don't touch it.
        Storage::fake('kb');

        Storage::disk('kb-hr')->put('docs/hr-doc.md', 'hr');
        Storage::disk('kb-hr')->put('docs/hr-orphan.md', 'o');
        // Decoy: a file on the default disk must stay untouched.
        Storage::disk('kb')->put('docs/decoy.md', 'd');

        $this->seedDoc('docs/hr-doc.md', 'hrd', 'hr-portal', disk: 'kb-hr');

        $this->artisan('kb:prune-orphan-files', ['--project' => 'hr-portal'])
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb-hr')->assertExists('docs/hr-doc.md');
        Storage::disk('kb-hr')->assertMissing('docs/hr-orphan.md');
        Storage::disk('kb')->assertExists('docs/decoy.md');
    }

    /**
     * R8: when KB_PATH_PREFIX is configured the command must scope its
     * listing to that subtree. Files outside the prefix are never scanned,
     * never reported as orphans, and never deleted — even if they have no
     * DB row. This protects shared buckets that host more than just the KB.
     */
    public function test_path_prefix_scopes_listing_and_orphan_detection(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.path_prefix', 'kb/proj');

        // Inside the prefix: one tracked file + one orphan.
        Storage::disk('kb')->put('kb/proj/docs/kept.md', 'k');
        Storage::disk('kb')->put('kb/proj/docs/orphan.md', 'o');

        // Outside the prefix: unrelated markdown files without DB rows.
        // They must NOT be reported as orphans and NOT be deleted.
        Storage::disk('kb')->put('other/outside.md', 'x');
        Storage::disk('kb')->put('outside-root.md', 'y');

        // DocumentIngestor stores source_path without the prefix.
        $this->seedDoc('docs/kept.md', 'hk', prefix: 'kb/proj');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertExists('kb/proj/docs/kept.md');
        Storage::disk('kb')->assertMissing('kb/proj/docs/orphan.md');
        // Outside-prefix files remain untouched.
        Storage::disk('kb')->assertExists('other/outside.md');
        Storage::disk('kb')->assertExists('outside-root.md');
    }

    /**
     * Windows operators sometimes set KB_PATH_PREFIX=kb\proj. The command
     * must normalise backslashes before scoping the listing and stripping
     * the prefix, otherwise prefix-strip silently leaks and every file
     * looks like an orphan (potentially deleting the whole corpus).
     */
    public function test_windows_style_backslashes_in_prefix_are_normalized(): void
    {
        Storage::fake('kb');
        config()->set('kb.sources.path_prefix', 'kb\\proj');

        Storage::disk('kb')->put('kb/proj/docs/kept.md', 'k');
        Storage::disk('kb')->put('kb/proj/docs/orphan.md', 'o');

        // Recorded as the operator typed it: the resolver normalises it.
        $this->seedDoc('docs/kept.md', 'hk', prefix: 'kb\\proj');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertExists('kb/proj/docs/kept.md');
        Storage::disk('kb')->assertMissing('kb/proj/docs/orphan.md');
    }
}
