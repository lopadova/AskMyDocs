<?php

namespace Tests\Feature\Kb;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDeleterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::fake('kb');
    }

    private function makeDocument(array $overrides = []): KnowledgeDocument
    {
        $document = KnowledgeDocument::create(array_merge([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Sample',
            'source_path' => 'docs/sample.md',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', 'sample'),
            'version_hash' => hash('sha256', 'sample'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now(),
        ], $overrides));

        KnowledgeChunk::create([
            'knowledge_document_id' => $document->id,
            'project_key' => $document->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$document->id),
            'heading_path' => null,
            'chunk_text' => 'body',
            'metadata' => [],
            'embedding' => [0.1, 0.2, 0.3],
        ]);

        return $document;
    }

    public function test_soft_delete_marks_deleted_at_and_keeps_file_and_chunks(): void
    {
        config()->set('kb.deletion.soft_delete', true);

        Storage::disk('kb')->put('docs/sample.md', '# hi');
        $document = $this->makeDocument();

        $deleter = new DocumentDeleter;
        $result = $deleter->delete($document);

        $this->assertSame('soft', $result['mode']);
        $this->assertFalse($result['file_deleted']);
        Storage::disk('kb')->assertExists('docs/sample.md');

        $this->assertNull(KnowledgeDocument::find($document->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($document->id));
        $this->assertSame(1, KnowledgeChunk::where('knowledge_document_id', $document->id)->count());
    }

    public function test_force_option_overrides_soft_delete_config(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', '# hi');
        $document = $this->makeDocument();

        $result = (new DocumentDeleter)->delete($document, force: true);

        $this->assertSame('hard', $result['mode']);
        $this->assertTrue($result['file_deleted']);
        Storage::disk('kb')->assertMissing('docs/sample.md');
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
        $this->assertSame(0, KnowledgeChunk::where('knowledge_document_id', $document->id)->count());
    }

    public function test_hard_delete_via_config_default(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        Storage::disk('kb')->put('docs/sample.md', '# hi');
        $document = $this->makeDocument();

        $result = (new DocumentDeleter)->delete($document);

        $this->assertSame('hard', $result['mode']);
        Storage::disk('kb')->assertMissing('docs/sample.md');
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
    }

    public function test_hard_delete_returns_file_deleted_false_when_file_missing(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        $document = $this->makeDocument(); // no file on disk

        $result = (new DocumentDeleter)->delete($document);

        $this->assertSame('hard', $result['mode']);
        $this->assertFalse($result['file_deleted']);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
    }

    public function test_hard_delete_applies_path_prefix_from_metadata(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        config()->set('kb.sources.path_prefix', 'tenant-a');
        Storage::disk('kb')->put('tenant-a/docs/sample.md', '# hi');

        $document = $this->makeDocument([
            'metadata' => ['disk' => 'kb', 'prefix' => 'tenant-a'],
        ]);

        $result = (new DocumentDeleter)->delete($document);

        $this->assertTrue($result['file_deleted']);
        Storage::disk('kb')->assertMissing('tenant-a/docs/sample.md');
    }

    public function test_delete_by_path_returns_null_when_not_found(): void
    {
        $result = (new DocumentDeleter)->deleteByPath('missing', 'nope.md');

        $this->assertNull($result);
    }

    public function test_delete_by_path_soft_deletes_matching_row(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        $document = $this->makeDocument();

        $result = (new DocumentDeleter)->deleteByPath($document->project_key, $document->source_path);

        $this->assertNotNull($result);
        $this->assertSame('soft', $result['mode']);
        $this->assertNull(KnowledgeDocument::find($document->id));
    }

    public function test_delete_by_path_force_hard_deletes_already_soft_deleted_row(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $document = $this->makeDocument();

        $deleter = new DocumentDeleter;
        $first = $deleter->deleteByPath($document->project_key, $document->source_path);
        $this->assertSame('soft', $first['mode']);

        // Force a hard delete on the already-soft-deleted row. With the
        // previous implementation this would return null.
        $second = $deleter->deleteByPath($document->project_key, $document->source_path, force: true);

        $this->assertNotNull($second);
        $this->assertSame('hard', $second['mode']);
        $this->assertTrue($second['file_deleted']);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
        Storage::disk('kb')->assertMissing('docs/sample.md');
    }

    public function test_delete_by_path_is_idempotent_on_already_soft_deleted_row(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/sample.md', 'hi');
        $document = $this->makeDocument();

        $deleter = new DocumentDeleter;
        $deleter->deleteByPath($document->project_key, $document->source_path);
        $originalDeletedAt = KnowledgeDocument::withTrashed()->find($document->id)->deleted_at;

        // A second soft-delete must not bump deleted_at nor touch the file.
        $result = $deleter->deleteByPath($document->project_key, $document->source_path);

        $this->assertSame('soft', $result['mode']);
        $this->assertFalse($result['file_deleted']);
        $this->assertSame(
            $originalDeletedAt?->toIso8601String(),
            KnowledgeDocument::withTrashed()->find($document->id)->deleted_at?->toIso8601String(),
        );
        Storage::disk('kb')->assertExists('docs/sample.md');
    }

    public function test_delete_orphans_removes_only_rows_whose_file_is_missing(): void
    {
        config()->set('kb.deletion.soft_delete', true);

        $kept = $this->makeDocument(['source_path' => 'docs/a.md', 'version_hash' => 'keep']);
        $orphan = $this->makeDocument(['source_path' => 'docs/b.md', 'version_hash' => 'orphan']);
        // Different folder — must NOT be pruned.
        $sibling = $this->makeDocument(['source_path' => 'other/c.md', 'version_hash' => 'sibling']);

        $result = (new DocumentDeleter)->deleteOrphans(
            projectKey: 'demo',
            basePath: 'docs',
            existingRelativePaths: ['docs/a.md'],
        );

        $this->assertCount(1, $result);
        $this->assertSame('docs/b.md', $result[0]['source_path']);

        $this->assertNotNull(KnowledgeDocument::find($kept->id));
        $this->assertNotNull(KnowledgeDocument::find($sibling->id));
        $this->assertNull(KnowledgeDocument::find($orphan->id));
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($orphan->id));
    }

    public function test_delete_orphans_respects_project_boundary(): void
    {
        $docA = $this->makeDocument(['project_key' => 'proj-a', 'source_path' => 'docs/a.md', 'version_hash' => 'a']);
        $docB = $this->makeDocument(['project_key' => 'proj-b', 'source_path' => 'docs/a.md', 'version_hash' => 'b']);

        (new DocumentDeleter)->deleteOrphans(
            projectKey: 'proj-a',
            basePath: 'docs',
            existingRelativePaths: [],
        );

        $this->assertNull(KnowledgeDocument::find($docA->id));
        $this->assertNotNull(KnowledgeDocument::find($docB->id));
    }

    public function test_delete_orphans_respects_tenant_boundary_when_tenant_id_passed(): void
    {
        // R30 + Copilot iter 1 finding (PR #117). Two tenants legitimately
        // share `project_key='demo'`. The orphan sweep, when invoked on
        // behalf of tenant A, must NOT touch tenant B's rows even though
        // their source files are also missing from tenant A's existing
        // file list.
        config()->set('kb.deletion.soft_delete', true);

        // Both tenants own a doc at the same project_key + same folder.
        // Different source_path so the global UNIQUE on
        // (project_key, source_path, version_hash) does not collide.
        $docA = $this->makeDocument([
            'tenant_id' => 'tenant-a',
            'project_key' => 'demo',
            'source_path' => 'docs/tenant-a-file.md',
            'version_hash' => 'va',
        ]);
        $docB = $this->makeDocument([
            'tenant_id' => 'tenant-b',
            'project_key' => 'demo',
            'source_path' => 'docs/tenant-b-file.md',
            'version_hash' => 'vb',
        ]);

        // Tenant A reports ZERO existing files — naively, both A's and
        // B's rows are "orphan" under this base path. The tenant filter
        // is the load-bearing isolation guarantee.
        $result = (new DocumentDeleter)->deleteOrphans(
            projectKey: 'demo',
            basePath: 'docs',
            existingRelativePaths: [],
            tenantId: 'tenant-a',
        );

        // Only tenant A's row should be soft-deleted.
        $this->assertCount(1, $result);
        $this->assertSame('docs/tenant-a-file.md', $result[0]['source_path']);

        $this->assertNull(KnowledgeDocument::find($docA->id), 'tenant-a row soft-deleted');
        $this->assertNotNull(
            KnowledgeDocument::find($docB->id),
            'tenant-b row MUST survive tenant-a orphan sweep (R30).',
        );
        // And tenant-b must not even be reachable via withTrashed→deleted.
        $this->assertNull(KnowledgeDocument::withTrashed()->find($docB->id)?->deleted_at);
    }

    public function test_prune_soft_deleted_hard_deletes_old_rows_and_removes_files(): void
    {
        config()->set('kb.deletion.soft_delete', true);

        Storage::disk('kb')->put('docs/old.md', '# old');
        Storage::disk('kb')->put('docs/recent.md', '# recent');

        $old = $this->makeDocument(['source_path' => 'docs/old.md', 'version_hash' => 'old']);
        $recent = $this->makeDocument(['source_path' => 'docs/recent.md', 'version_hash' => 'recent']);

        $deleter = new DocumentDeleter;
        $deleter->delete($old);    // soft
        $deleter->delete($recent); // soft

        // Backdate $old's deletion past the retention window.
        KnowledgeDocument::withTrashed()
            ->where('id', $old->id)
            ->update(['deleted_at' => now()->subDays(45)]);

        $purged = $deleter->pruneSoftDeleted(now()->subDays(30));

        $this->assertSame(1, $purged);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($old->id));
        Storage::disk('kb')->assertMissing('docs/old.md');

        // The recent soft-delete must still be recoverable on disk and in DB.
        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($recent->id));
        Storage::disk('kb')->assertExists('docs/recent.md');
    }

    public function test_prune_preserves_file_referenced_by_a_live_version(): void
    {
        config()->set('kb.deletion.soft_delete', true);
        Storage::disk('kb')->put('docs/shared.md', '# current');

        $old = $this->makeDocument([
            'source_path' => 'docs/shared.md',
            'version_hash' => 'old-version',
            'status' => 'archived',
        ]);
        $live = $this->makeDocument([
            'source_path' => 'docs/shared.md',
            'version_hash' => 'live-version',
            'status' => 'active',
        ]);

        (new DocumentDeleter)->delete($old);
        KnowledgeDocument::withTrashed()
            ->whereKey($old->id)
            ->update(['deleted_at' => now()->subDays(45)]);

        $purged = (new DocumentDeleter)->pruneSoftDeleted(now()->subDays(30));

        $this->assertSame(1, $purged);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($old->id));
        $this->assertNotNull(KnowledgeDocument::find($live->id));
        Storage::disk('kb')->assertExists('docs/shared.md');
    }

    public function test_hard_delete_preserves_file_referenced_by_an_archived_version(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        Storage::disk('kb')->put('docs/shared.md', '# current');

        $deleted = $this->makeDocument([
            'source_path' => 'docs/shared.md',
            'version_hash' => 'deleted-version',
        ]);
        $archived = $this->makeDocument([
            'source_path' => 'docs/shared.md',
            'version_hash' => 'archived-version',
            'status' => 'archived',
        ]);

        $result = (new DocumentDeleter)->delete($deleted);

        $this->assertFalse($result['file_deleted']);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($deleted->id));
        $this->assertNotNull(KnowledgeDocument::find($archived->id));
        Storage::disk('kb')->assertExists('docs/shared.md');
    }

    public function test_hard_delete_does_not_treat_a_different_storage_key_as_a_reference(): void
    {
        config()->set('kb.deletion.soft_delete', false);
        Storage::fake('archive');
        Storage::disk('kb')->put('docs/shared.md', '# current');
        Storage::disk('archive')->put('history/docs/shared.md', '# archived');

        $deleted = $this->makeDocument([
            'source_path' => 'docs/shared.md',
            'version_hash' => 'deleted-version',
        ]);
        $this->makeDocument([
            'source_path' => 'docs/shared.md',
            'version_hash' => 'other-storage-version',
            'status' => 'archived',
            'metadata' => ['disk' => 'archive', 'prefix' => 'history'],
        ]);

        $result = (new DocumentDeleter)->delete($deleted);

        $this->assertTrue($result['file_deleted']);
        Storage::disk('kb')->assertMissing('docs/shared.md');
        Storage::disk('archive')->assertExists('history/docs/shared.md');
    }

    /**
     * The recorded storage namespace has ONE reading (StorageNamespace): only
     * a non-empty string disk is recorded. A null, empty or malformed value
     * is a legacy, ambiguous row that references its path on every disk —
     * the same answer the artifact gate gives — never "recorded, elsewhere".
     */
    public function test_a_null_empty_or_malformed_recorded_disk_is_a_legacy_reference_on_every_disk(): void
    {
        $deleter = app(DocumentDeleter::class);
        foreach ([['disk' => null, 'prefix' => ''], ['disk' => '', 'prefix' => ''], ['disk' => ['x'], 'prefix' => ''], ['prefix' => ''], []] as $i => $metadata) {
            $document = $this->makeDocument(['metadata' => $metadata, 'source_path' => 'docs/legacy-'.$i.'.md', 'document_hash' => hash('sha256', json_encode($metadata)), 'version_hash' => hash('sha256', json_encode($metadata))]);
            $this->assertFalse($deleter->documentRecordsStorageNamespace($document), json_encode($metadata));
            $this->assertTrue($deleter->documentReferencesStorageKey($document, 'kb', $document->source_path), json_encode($metadata));
            $this->assertTrue($deleter->documentReferencesStorageKey($document, 'other-disk', $document->source_path), json_encode($metadata));
        }
        $recorded = $this->makeDocument(['metadata' => ['disk' => 'other-disk', 'prefix' => ''], 'source_path' => 'docs/recorded.md', 'document_hash' => hash('sha256', 'r'), 'version_hash' => hash('sha256', 'r')]);
        $this->assertTrue($deleter->documentRecordsStorageNamespace($recorded));
        $this->assertFalse($deleter->documentReferencesStorageKey($recorded, 'kb', 'docs/recorded.md'), 'a recorded disk elsewhere is not a reference here');
        $this->assertTrue($deleter->documentReferencesStorageKey($recorded, 'other-disk', 'docs/recorded.md'));

        // The artifact reference gate answers alike for every shape (judged in PHP through StorageNamespace,
        // so a malformed value — an array — counts as a reference exactly like a null or an empty one).
        foreach ([['', 'empty'], [null, 'null']] as [$value, $label]) { // pairs: a null array KEY would collapse onto ''
            $pointer = '.artifacts/t/demo/docs/legacy-'.$label.'.md.versions/'.str_repeat('a', 64).'.md';
            $this->makeDocument(['metadata' => ['disk' => $value, 'prefix' => ''], 'source_path' => 'docs/legacy-pointer-'.$label.'.md', 'markdown_path' => $pointer, 'document_hash' => hash('sha256', 'p'.$label), 'version_hash' => hash('sha256', 'p'.$label)]);
            $this->assertTrue($deleter->artifactReferenced('kb', $pointer), $label);
            $this->assertTrue($deleter->artifactReferenced('other-disk', $pointer), $label);
        }
        $malformed = '.artifacts/t/demo/docs/legacy-malformed.md.versions/'.str_repeat('b', 64).'.md';
        $this->makeDocument(['metadata' => ['disk' => ['x'], 'prefix' => ''], 'source_path' => 'docs/legacy-pointer-malformed.md', 'markdown_path' => $malformed, 'document_hash' => hash('sha256', 'pm'), 'version_hash' => hash('sha256', 'pm')]);
        $this->assertTrue($deleter->artifactReferenced('kb', $malformed));
        $this->assertTrue($deleter->artifactReferenced('other-disk', $malformed));
        $elsewhere = '.artifacts/t/demo/docs/elsewhere.md.versions/'.str_repeat('c', 64).'.md';
        $this->makeDocument(['metadata' => ['disk' => 'other-disk', 'prefix' => ''], 'source_path' => 'docs/elsewhere-pointer.md', 'markdown_path' => $elsewhere, 'document_hash' => hash('sha256', 'pe'), 'version_hash' => hash('sha256', 'pe')]);
        $this->assertFalse($deleter->artifactReferenced('kb', $elsewhere), 'a recorded disk elsewhere does not reference this one');
        $this->assertTrue($deleter->artifactReferenced('other-disk', $elsewhere));
    }

    /**
     * The batch gate judges every chunk on its own: with more than one
     * chunk of 500 paths, a referenced path in the second chunk must be
     * reported referenced too (a cumulative count that satisfied the
     * per-chunk target after the first row would report the rest of the
     * chunk unreferenced — and a caller would delete a live row's artifact).
     */
    public function test_the_batch_artifact_gate_reports_every_referenced_path_across_chunks(): void
    {
        $paths = [];
        $rows = [];
        for ($i = 0; $i < 502; $i++) {
            $path = '.artifacts/t/demo/docs/bulk-'.$i.'.md.versions/'.hash('sha256', 'bulk-'.$i).'.md';
            $paths[] = $path;
            $rows[] = [
                'tenant_id' => 'default',
                'project_key' => 'demo',
                'source_type' => 'markdown',
                'title' => 'Bulk '.$i,
                'source_path' => 'docs/bulk-'.$i.'.md',
                'mime_type' => 'text/markdown',
                'language' => 'it',
                'access_scope' => 'internal',
                'status' => 'active',
                'document_hash' => hash('sha256', 'bulk-'.$i),
                'version_hash' => hash('sha256', 'bulk-'.$i),
                'markdown_path' => $path,
                'metadata' => json_encode(['disk' => 'kb', 'prefix' => '']),
                'indexed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $batch) {
            \Illuminate\Support\Facades\DB::table('knowledge_documents')->insert($batch);
        }
        $unreferenced = '.artifacts/t/demo/docs/nobody.md.versions/'.str_repeat('d', 64).'.md';

        $referenced = app(DocumentDeleter::class)->artifactsReferenced('kb', [...$paths, $unreferenced]);

        $this->assertCount(502, $referenced);
        $this->assertArrayHasKey($paths[500], $referenced, 'the first path of the second chunk');
        $this->assertArrayHasKey($paths[501], $referenced, 'the last path of the second chunk');
        $this->assertArrayNotHasKey($unreferenced, $referenced);
    }

    public function test_hard_delete_refuses_storage_call_for_traversal_path(): void
    {
        // Iteration 4 (PR #116) — R1 + R4 + R14. KbPath::normalize()
        // throws on traversal segments. Previously, the catch block in
        // resolveFullPath() returned the raw `prefix/source_path`
        // string — DEFEATING the traversal guard because Storage::delete()
        // was then handed an attacker-controlled key. The fix routes
        // un-normalisable paths to a no-op file delete (DB rows still
        // hard-deleted; the warning surfaces in the log).
        config()->set('kb.deletion.soft_delete', false);

        // Place a sentinel "real" file that an attacker would hope to
        // pivot the disk-delete onto via a traversal segment. The
        // bypass is impossible if resolveFullPath returns null and the
        // caller skips Storage::delete() entirely.
        Storage::disk('kb')->put('etc/passwd', 'sentinel');

        $document = $this->makeDocument([
            'source_path' => '../../etc/passwd',
            'version_hash' => 'traversal-attempt',
        ]);

        $result = (new DocumentDeleter)->delete($document);

        $this->assertSame('hard', $result['mode']);
        // No file should have been deleted (traversal guard kept the
        // pivot file intact). The sentinel file MUST still exist.
        $this->assertFalse($result['file_deleted']);
        Storage::disk('kb')->assertExists('etc/passwd');

        // The DB row + chunks still got hard-deleted (the rows are
        // ours; only the file path was un-normalisable).
        $this->assertNull(KnowledgeDocument::withTrashed()->find($document->id));
        $this->assertSame(0, KnowledgeChunk::where('knowledge_document_id', $document->id)->count());
    }
}
