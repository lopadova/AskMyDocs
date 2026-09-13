<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->expectsOutputToContain('artifact_temps_swept=1 artifact_orphans_removed=1')
            ->assertExitCode(0);

        Storage::disk('kb')->assertExists((string) $live->markdown_path);
        Storage::disk('kb')->assertExists((string) $trashed->markdown_path);
        Storage::disk('kb')->assertMissing($orphan);
        Storage::disk('kb')->assertMissing($staleTmp);
        Storage::disk('kb')->assertExists($freshTmp);
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
            ->expectsOutputToContain('artifact_orphans_removed=1 (dry-run)')
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
        // already has an artifact: not a candidate
        $has = $this->row(4, 'active', 'stored', 'docs/has.md');
        // archived: not live, not a candidate
        Storage::disk('kb')->put('docs/old.md', "# Doc\n\nversion 5\n");
        $this->row(5, 'archived', null, 'docs/old.md');

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant, '--dry-run' => true])
            ->expectsOutputToContain('written=1 intentionally_missing=0 source_missing=1 hash_mismatch=1 conversion_failed=0 (dry-run)')
            ->assertExitCode(0);
        $this->assertNull($match->fresh()->markdown_path);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => $tenant])
            ->expectsOutputToContain('written=1 intentionally_missing=0 source_missing=1 hash_mismatch=1 conversion_failed=0')
            ->assertExitCode(0);

        $written = $match->fresh();
        $this->assertNotNull($written->markdown_path);
        $this->assertSame($written->document_hash, $written->content_hash);
        $this->assertSame("# Doc\n\nversion 1\n", Storage::disk('kb')->get((string) $written->markdown_path));
        $this->assertNull($drift->fresh()->markdown_path);
        $this->assertNull($gone->fresh()->markdown_path);
        $this->assertSame($has->markdown_path, $has->fresh()->markdown_path);
    }

    public function test_backfill_reports_reference_only_rows_as_intentionally_missing_and_refuses_a_blank_tenant(): void
    {
        Storage::disk('kb')->put('docs/ref.md', "# Doc\n\nversion 1\n");
        $row = $this->row(1, 'active', null, 'docs/ref.md');
        config(['kb.source_retention.mode' => 'reference_only']);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => app(TenantContext::class)->current()])
            ->expectsOutputToContain('written=0 intentionally_missing=1')
            ->assertExitCode(0);
        $this->assertNull($row->fresh()->markdown_path);

        $this->artisan('kb:artifacts-backfill', ['--tenant' => ''])->assertExitCode(1);

        config(['kb.conversion_artifacts.enabled' => false, 'kb.source_retention.mode' => 'full_copy']);
        $this->artisan('kb:artifacts-backfill', ['--tenant' => app(TenantContext::class)->current()])
            ->expectsOutputToContain('KB_CONVERSION_ARTIFACTS_ENABLED=false')
            ->assertExitCode(1);
    }
}
