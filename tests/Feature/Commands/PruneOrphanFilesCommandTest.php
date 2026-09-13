<?php

namespace Tests\Feature\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrFigureStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class PruneOrphanFilesCommandTest extends TestCase
{
    use RefreshDatabase;

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

    private function seedDoc(string $sourcePath, string $versionHash, string $project = 'demo'): KnowledgeDocument
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
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ]);
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
     * v8.36 / ADR 0029 — an orphan source (a failed first ingest) may have
     * left an OCR run beside it; the sweep removes both, and never treats
     * the run's own files as orphan candidates.
     */
    public function test_deleting_an_orphan_source_purges_the_ocr_run_beside_it(): void
    {
        Storage::fake('kb');

        Storage::disk('kb')->put('docs/kept.md', 'k');
        Storage::disk('kb')->put('docs/orphan.md', 'o');
        Storage::disk('kb')->put('docs/orphan.md.ocr/0123456789abcdef/images/fig-1-1.png', 'figure');
        Storage::disk('kb')->put('docs/orphan.md.ocr/0123456789abcdef/result.json', '{}');
        Storage::disk('kb')->put('docs/kept.md.ocr/fedcba9876543210/notes.md', 'not a source');

        $this->seedDoc('docs/kept.md', 'hk');

        // Past the in-flight grace (ADR 0029 §6): the run is not a reservation any more.
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertMissing('docs/orphan.md');
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/orphan.md.ocr'), 'the orphan run goes with its source');
        Storage::disk('kb')->assertExists('docs/kept.md');
        Storage::disk('kb')->assertExists('docs/kept.md.ocr/fedcba9876543210/notes.md'); // a run beside a live source is neither a candidate nor purged
    }

    /**
     * A `.ocr` tree whose source is gone from the disk and from every row
     * (a hard delete that kept an in-flight run) is swept here — grace-aware:
     * a run recorded inside the in-flight window is kept, an aged one goes.
     * The source-row check is cross-tenant and includes trashed rows (the
     * deleter's R30 exception), and a tree beside a still-referenced key is
     * never a candidate.
     */
    public function test_a_dangling_ocr_tree_is_swept_only_once_it_has_aged_past_the_in_flight_grace(): void
    {
        Storage::fake('kb');
        Storage::disk('kb')->put('docs/gone.md.ocr/0123456789abcdef/images/fig-1-1.png', 'figure');
        Storage::disk('kb')->put('docs/gone.md.ocr/0123456789abcdef/result.json', '{}');
        // Source gone from the disk but a soft-deleted row of ANOTHER tenant
        // still references the key: the tree is that row's, not dangling.
        Storage::disk('kb')->put('docs/theirs.md.ocr/fedcba9876543210/result.json', '{}');
        $theirs = $this->seedDoc('docs/theirs.md', 'ht');
        KnowledgeDocument::withoutGlobalScopes()->whereKey($theirs->id)->update(['tenant_id' => 'other-tenant', 'deleted_at' => now()]);

        $this->artisan('kb:prune-orphan-files', ['--dry-run' => true])
            ->expectsOutputToContain('docs/gone.md.ocr')
            ->expectsOutputToContain('0 of 0 orphan file(s) and 1 dangling OCR tree(s)')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists('docs/gone.md.ocr/0123456789abcdef/result.json');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=0 in_flight=1 ocr_failed=0')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists('docs/gone.md.ocr/0123456789abcdef/result.json');

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=1 in_flight=0 ocr_failed=0')
            ->assertSuccessful();
        $this->assertFalse(Storage::disk('kb')->directoryExists('docs/gone.md.ocr'));
        Storage::disk('kb')->assertExists('docs/theirs.md.ocr/fedcba9876543210/result.json');
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

    public function test_delete_failure_is_surfaced_as_nonzero_exit(): void
    {
        // Don't use Storage::fake — it always succeeds on delete. Use a
        // Mockery spy for the whole disk instead. R4: never swallow failures.
        $fake = Mockery::mock(Filesystem::class);
        $fake->shouldReceive('allFiles')
            ->andReturn(['docs/orphan.md']);
        $fake->shouldReceive('delete')
            ->with('docs/orphan.md')
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

        $this->seedDoc('docs/hr-doc.md', 'hrd', 'hr-portal');

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
        $this->seedDoc('docs/kept.md', 'hk');

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

        $this->seedDoc('docs/kept.md', 'hk');

        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('scanned=2 orphans=1 deleted=1 failed=0')
            ->assertSuccessful();

        Storage::disk('kb')->assertExists('kb/proj/docs/kept.md');
        Storage::disk('kb')->assertMissing('kb/proj/docs/orphan.md');
    }
}
