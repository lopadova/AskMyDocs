<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\CanonicalIndexerJob;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v8.7/W5 — Cloud Time Machine: version timeline + diff + restore.
 */
final class KbDocumentVersionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * `$canonical` gives the row a LIVE canonical identity. `$wasCanonical`
     * builds an ARCHIVED canonical version faithfully: archiving vacates
     * `doc_id`/`slug`/`canonical_status`/`is_canonical` but PRESERVES
     * `canonical_type` and `frontmatter_json`, which is what a restore
     * reconstructs the identity from. `$legacyCanonical` is the pre-v8.36
     * shape of the same thing: `canonical_type` survived, the frontmatter was
     * never persisted, so the identity can only be carried from the outgoing
     * live version.
     */
    private function makeVersion(
        string $hashSeed,
        string $status,
        string $body,
        bool $canonical = false,
        bool $wasCanonical = false,
        bool $legacyCanonical = false,
        string $slug = 'dec-1',
        string $sourcePath = 'docs/dec.md',
    ): KnowledgeDocument {
        $doc = KnowledgeDocument::create(array_merge([
            'project_key' => 'eng',
            'source_path' => $sourcePath,
            'source_type' => 'markdown',
            'title' => 'Decision',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', $hashSeed),
            'version_hash' => hash('sha256', $hashSeed),
            'metadata' => [],
            'indexed_at' => now()->subMinutes(strlen($hashSeed)),
        ], $canonical ? [
            'is_canonical' => true,
            'doc_id' => $slug,
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'retrieval_priority' => 80,
            'frontmatter_json' => ['id' => $slug, 'slug' => $slug, 'type' => 'decision', 'status' => 'accepted'],
        ] : ($legacyCanonical ? [
            'is_canonical' => false,
            'doc_id' => null,
            'slug' => null,
            'canonical_type' => 'decision',
            'canonical_status' => null,
            'retrieval_priority' => 80,
            'frontmatter_json' => null,
        ] : ($wasCanonical ? [
            'is_canonical' => false,
            'doc_id' => null,
            'slug' => null,
            'canonical_type' => 'decision',
            'canonical_status' => null,
            'retrieval_priority' => 80,
            'frontmatter_json' => ['id' => $slug, 'slug' => $slug, 'type' => 'decision', 'status' => 'accepted'],
        ] : []))));

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'eng',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $hashSeed.'chunk'),
            'heading_path' => 'Decision',
            'chunk_text' => $body,
            'metadata' => [],
        ]);

        return $doc;
    }

    public function test_index_lists_the_version_timeline(): void
    {
        $admin = $this->makeAdmin();
        $v1 = $this->makeVersion('v1aaa', 'archived', 'old body');
        $live = $this->makeVersion('v2bbb', 'active', 'new body', canonical: true);

        $resp = $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions");

        $resp->assertOk()->assertJsonCount(2, 'data');
        // Newest (live) first.
        $this->assertSame($live->id, $resp->json('data.0.id'));
        $this->assertTrue($resp->json('data.0.is_live'));
        $this->assertFalse($resp->json('data.1.is_live'));
        $this->assertSame($v1->id, $resp->json('data.1.id'));
    }

    public function test_diff_reports_added_and_removed_lines(): void
    {
        $admin = $this->makeAdmin();
        $v1 = $this->makeVersion('v1aaa', 'archived', "line a\nline b");
        $live = $this->makeVersion('v2bbb', 'active', "line a\nline c");

        $resp = $this->actingAs($admin)
            ->getJson("/api/admin/kb/documents/{$live->id}/versions/diff?from={$v1->id}&to={$live->id}");

        $resp->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.removed', 1);
    }

    public function test_restore_makes_an_archived_version_live_and_reclaims_its_own_canonical_identity(): void
    {
        $admin = $this->makeAdmin();
        // The archived version declared a DIFFERENT slug from the live one, so
        // the assertions can tell "reconstructed from its own frontmatter"
        // apart from "copied off whatever was live".
        $archived = $this->makeVersion('v1aaa', 'archived', 'old body', wasCanonical: true, slug: 'dec-older');
        $live = $this->makeVersion('v2bbb', 'active', 'new body', canonical: true);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', true)
            ->assertJsonPath('data.slug', 'dec-older');

        $archived->refresh();
        $live->refresh();
        $this->assertSame('active', $archived->status);
        $this->assertSame('dec-older', $archived->slug, 'its own slug, not the live row\'s');
        $this->assertSame('dec-older', $archived->doc_id);
        $this->assertSame('decision', $archived->canonical_type, 'the type comes back with the identity');
        $this->assertTrue((bool) $archived->is_canonical);
        // The outgoing live version is archived + its identity vacated.
        $this->assertSame('archived', $live->status);
        $this->assertNull($live->slug);
        $this->assertFalse((bool) $live->is_canonical);
    }

    /**
     * v8.36 / PR #479 Copilot review round 5 (R30) — the response
     * `DocumentVersionService::restore()` returns is read AFTER the
     * transaction commits, by `$target->id` alone. `BelongsToTenant`
     * installs no global scope, so that final read must carry an explicit
     * tenant predicate itself — the transaction's own re-read already does
     * (`forTenant($tenantId)->where('id', $target->id)->lockForUpdate()`),
     * and this is the SAME boundary applied to the read that produces the
     * caller-visible result. Captured via `DB::listen()` rather than
     * engineering an actual cross-tenant PK collision (Laravel's default
     * auto-increment `id` makes one artificial to construct honestly): the
     * SQL of the LAST SELECT this endpoint issues on `knowledge_documents`
     * by primary key must itself carry `tenant_id =`, proving the fix is on
     * the query that answers the caller, not only the query inside the
     * transaction.
     */
    public function test_restore_response_is_read_back_through_the_tenant_boundary(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1ccc', 'archived', 'old body');

        $lastFinalSelectSql = null;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$lastFinalSelectSql): void {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select') && str_contains($sql, 'knowledge_documents') && str_contains($sql, '"id" = ?')) {
                $lastFinalSelectSql = $sql;
            }
        });

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk();

        $this->assertNotNull($lastFinalSelectSql, 'no primary-key SELECT on knowledge_documents was observed');
        $this->assertStringContainsString('tenant_id', $lastFinalSelectSql, 'the final read-back must be tenant-scoped, not a bare PK lookup');
    }

    /**
     * R21 — concurrent-restore sweep.
     *
     * Simulates the PostgreSQL EvalPlanQual scenario where a second version
     * was activated by a concurrent restore while *this* restore was waiting
     * on the lock. The $live SELECT FOR UPDATE returns null in that case
     * (formerly-active row is now archived), so without the sweep the
     * concurrent version stays active alongside our just-activated version.
     *
     * SQLite does not support FOR UPDATE, so we cannot exercise the lock
     * path directly. Instead we replicate the post-race DB state by hand
     * (two active rows) and assert that restore() leaves exactly one active.
     */
    public function test_restore_sweep_archives_concurrently_activated_version(): void
    {
        $admin = $this->makeAdmin();

        // v1 — the target we are about to restore.
        $v1 = $this->makeVersion('v1ccc', 'archived', 'old body');

        // v2 — was the live version before a concurrent restore activated v3.
        // In the race scenario it is already archived before we reach the
        // $live lockForUpdate, so the $live query returns null.
        $this->makeVersion('v2ddd', 'archived', 'mid body');

        // v3 — activated by the concurrent thread; its status is 'active' now.
        // This is what the sweep must archive to restore the one-active
        // invariant.
        $concurrentlyActive = $this->makeVersion('v3eee', 'active', 'concurrent body');

        $this->actingAs($admin)
            ->postJson("/api/admin/kb/documents/{$v1->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $v1->refresh();
        $concurrentlyActive->refresh();

        $this->assertSame('active', $v1->status, 'Target version must be active after restore');
        $this->assertSame('archived', $concurrentlyActive->status, 'Concurrently-activated version must be swept to archived');

        // Exactly one active version in the family.
        $activeCount = KnowledgeDocument::query()
            ->where('project_key', 'eng')
            ->where('source_path', 'docs/dec.md')
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $activeCount, 'Exactly one active version must exist after sweep');
    }

    /**
     * R10 — a restore is the re-ingest of an older version's bytes, so the
     * canonical identity follows the CONTENT. Restoring an archived canonical
     * version over a NON-canonical live one must reclaim its own identity from
     * the frontmatter the archive retained; deriving it from the live row
     * (which has none) would silently demote it.
     */
    public function test_restoring_a_canonical_version_over_a_non_canonical_live_one_reclaims_its_own_identity(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1iii', 'archived', 'old canonical body', wasCanonical: true);
        $live = $this->makeVersion('v2jjj', 'active', 'plain body');

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.is_canonical', true)
            ->assertJsonPath('data.slug', 'dec-1');

        $archived->refresh();
        $this->assertTrue((bool) $archived->is_canonical);
        $this->assertSame('dec-1', $archived->slug);
        $this->assertSame('dec-1', $archived->doc_id);
        $this->assertSame('accepted', $archived->canonical_status);
        $this->assertSame('archived', $live->refresh()->status);
    }

    /**
     * R10 — the other direction: restoring a version that was NEVER canonical
     * over a canonical live one must not mark that content canonical with the
     * live row's slug. The family's slug is simply left unheld, exactly as it
     * would be if those bytes were re-ingested.
     */
    public function test_restoring_a_non_canonical_version_does_not_inherit_the_live_canonical_identity(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1kkk', 'archived', 'plain body');
        $live = $this->makeVersion('v2lll', 'active', 'canonical body', canonical: true);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.is_canonical', false)
            ->assertJsonPath('data.slug', null);

        $archived->refresh();
        $this->assertFalse((bool) $archived->is_canonical, 'content that never declared a slug is not canonical');
        $this->assertNull($archived->slug);
        $this->assertNull($archived->doc_id);
        $this->assertSame('archived', $live->refresh()->status);
        $this->assertSame(
            0,
            KnowledgeDocument::query()->where('project_key', 'eng')->where('slug', 'dec-1')->count(),
            'the slug is left unheld rather than attached to content that never declared it',
        );
    }

    /**
     * R14 / R21 — the reclaimed slug can still be held by a row the restore
     * does NOT vacate: an archived sibling (a re-ingest that dropped the
     * frontmatter vacates nothing) or a live row of another source path.
     * Writing it anyway raises on `uq_kb_doc_slug` — a 500 with a raw SQL
     * message. The content comes back regardless; only the identity is
     * dropped, and the refusal is logged with the holder named.
     */
    public function test_a_reclaimed_slug_held_by_another_document_degrades_the_restore_instead_of_failing_it(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1mmm', 'archived', 'old body', wasCanonical: true);
        $live = $this->makeVersion('v2nnn', 'active', 'new body');
        // Another FAMILY in the same project already holds `dec-1`.
        $holder = $this->makeVersion('v3ooo', 'active', 'other doc', canonical: true, sourcePath: 'docs/other.md');
        \Illuminate\Support\Facades\Log::spy();

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', false);

        $archived->refresh();
        $this->assertSame('active', $archived->status, 'the content is restored either way');
        $this->assertNull($archived->slug);
        $this->assertFalse((bool) $archived->is_canonical);
        $this->assertSame('dec-1', $holder->refresh()->slug, "the slug stays with its current holder");
        $this->assertSame('archived', $live->refresh()->status);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'held by another document'))
            ->once();
    }

    /**
     * Copilot round-8 finding on PR #479: `conflictingCanonicalHolderId()`
     * only ever locks an EXISTING holder — when the slot is genuinely free
     * at probe time there is nothing to lock, so two restores in different
     * families can both see "free" and race to claim the SAME identity.
     * This stages that exact race in-process (no cross-process interleaving
     * can be staged against SQLite, same limitation documented on the
     * archive-sweep test in ConversionArtifactsIngestTest): `DB::listen()`
     * fires right after the probe SELECT returns "nothing held" and, in
     * that window, makes ANOTHER family commit the same slug directly —
     * the concurrent restore that wins the race. Only then does THIS
     * restore's own identity-assignment UPDATE run and hit the composite
     * unique the probe never got a chance to see coming.
     */
    public function test_a_slug_claimed_by_a_concurrent_restore_in_the_probes_blind_spot_still_degrades_the_restore(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1qqq', 'archived', 'old body', wasCanonical: true);
        $this->makeVersion('v2ppp', 'active', 'new body');
        // The race's eventual winner: a real row NOT holding `dec-1` yet
        // when the probe runs — that is the whole point, nothing exists for
        // the probe to find or lock.
        $winner = $this->makeVersion('v3rrr', 'active', 'other doc', sourcePath: 'docs/other.md');
        \Illuminate\Support\Facades\Log::spy();

        $fired = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$fired, $winner): void {
            if ($fired || ! str_contains($query->sql, 'knowledge_documents')) {
                return;
            }
            $lower = strtolower($query->sql);
            // The identity probe is the only SELECT in this flow that tests
            // both `slug` and `doc_id` — it just reported the slot free.
            if (! str_starts_with(ltrim($lower), 'select') || ! str_contains($lower, 'slug') || ! str_contains($lower, 'doc_id')) {
                return;
            }
            $fired = true;
            \Illuminate\Support\Facades\DB::table('knowledge_documents')
                ->where('id', $winner->id)
                ->update(['slug' => 'dec-1', 'doc_id' => 'dec-1', 'is_canonical' => true, 'canonical_status' => 'accepted']);
        });

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', false);

        $this->assertTrue($fired, 'the race window was actually staged');
        $archived->refresh();
        $this->assertSame('active', $archived->status, 'the content is restored either way');
        $this->assertNull($archived->slug);
        $this->assertFalse((bool) $archived->is_canonical);
        $this->assertSame('dec-1', $winner->refresh()->slug, 'the concurrent winner keeps the slug it committed first');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'claimed its slug or doc_id first'))
            ->once();
    }

    /**
     * The probe has to see what the INDEX sees, not what this reader is
     * allowed to read. A soft-deleted holder keeps its slug — the unique
     * still rejects the write — so a probe under the default scopes would
     * report "free" and hand the restore the very 500 it exists to prevent.
     * (`AccessScopeScope` is lifted for the same structural reason: an
     * ACL-hidden holder is invisible to the reader and not to the database.)
     */
    public function test_a_reclaimed_slug_held_by_a_soft_deleted_document_still_degrades_the_restore(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1rrr', 'archived', 'old body', wasCanonical: true);
        $this->makeVersion('v2sss', 'active', 'new body');
        $holder = $this->makeVersion('v3ttt', 'active', 'other doc', canonical: true, sourcePath: 'docs/other.md');
        $holder->delete(); // soft — the row, and its slug, are still there
        \Illuminate\Support\Facades\Log::spy();

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', false);

        $archived->refresh();
        $this->assertSame('active', $archived->status, 'the content is restored either way');
        $this->assertNull($archived->slug, 'the slug a trashed row still holds is not reclaimed');
        $this->assertSame('dec-1', KnowledgeDocument::withTrashed()->findOrFail($holder->id)->slug);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, 'held by another document'))
            ->once();
    }

    /**
     * The other direction: since 2026_10_02_000011 the uniques start with
     * `tenant_id`, so a holder in ANOTHER tenant is not a conflict — the
     * database accepts the write and the probe must not invent a refusal.
     * A probe querying "global uniqueness" would strip the identity from a
     * restore the schema permits.
     */
    public function test_a_holder_in_another_tenant_does_not_block_the_reclaim(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1uuu', 'archived', 'old body', wasCanonical: true);
        $this->makeVersion('v2vvv', 'active', 'new body');

        $tenantContext = app(\App\Support\TenantContext::class);
        $mine = $tenantContext->current();
        $tenantContext->set('other-tenant');

        try {
            $foreign = $this->makeVersion('v3www', 'active', 'their doc', canonical: true, sourcePath: 'docs/theirs.md');
        } finally {
            // Unconditional (R16): a throw inside the foreign-tenant window
            // would otherwise leave the container singleton switched for the
            // rest of this test and make the failure unreadable.
            $tenantContext->set($mine);
        }

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', true);

        $this->assertSame('dec-1', $archived->refresh()->slug);
        $this->assertSame('dec-1', KnowledgeDocument::withoutGlobalScopes()->findOrFail($foreign->id)->slug, "the other tenant keeps its own");
    }

    /**
     * R10 §5/§9 — the graph projection follows the ACTIVE version, so a
     * restore moves it. Restoring a NON-canonical version is the case an
     * indexer dispatch cannot cover: it would short-circuit on a row with no
     * identity and leave the outgoing version's whole graph standing, so
     * every identity the restore vacated and did not hand on has its nodes
     * removed.
     */
    public function test_restoring_a_non_canonical_version_removes_the_outgoing_versions_graph_nodes(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1xxx', 'archived', 'plain body');
        $live = $this->makeVersion('v2yyy', 'active', 'canonical body', canonical: true);
        KbNode::create([
            'node_uid' => 'dec-1', 'node_type' => 'decision', 'label' => 'Dec 1',
            'project_key' => 'eng', 'source_doc_id' => 'dec-1', 'payload_json' => [],
        ]);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.is_canonical', false);

        $this->assertSame('archived', $live->refresh()->status);
        $this->assertSame(
            0,
            KbNode::withoutGlobalScopes()->where('project_key', 'eng')->where('source_doc_id', 'dec-1')->count(),
            'the graph of the version that is no longer live does not outlive it',
        );
        \Illuminate\Support\Facades\Queue::assertNotPushed(CanonicalIndexerJob::class);
    }

    /**
     * An identity is handed on only when BOTH halves move. Restoring
     * `(doc_id=dec-1, slug=dec-1)` over `(doc_id=dec-other, slug=dec-1)`
     * shares only the slug: the node still owned by `dec-other` is orphaned,
     * and it sits on the very `node_uid` the re-index is about to upsert
     * (`uq_kb_nodes_project_uid`). Matching on either half would leave it.
     */
    public function test_restoring_over_a_partially_matching_identity_still_removes_the_stale_node(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1pmi', 'archived', 'old body', wasCanonical: true);
        $live = $this->makeVersion('v2pmi', 'active', 'new body', canonical: true);
        // Same slug, different doc_id — only one half of the identity moves.
        KnowledgeDocument::withoutGlobalScopes()->whereKey($live->id)->update(['doc_id' => 'dec-other']);
        KbNode::create([
            'node_uid' => 'dec-1', 'node_type' => 'decision', 'label' => 'Dec 1',
            'project_key' => 'eng', 'source_doc_id' => 'dec-other', 'payload_json' => [],
        ]);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.is_canonical', true)
            ->assertJsonPath('data.slug', 'dec-1');

        $this->assertSame(
            0,
            KbNode::withoutGlobalScopes()->where('project_key', 'eng')->where('source_doc_id', 'dec-other')->count(),
            'the node owned by the doc_id that did NOT move is removed',
        );
        \Illuminate\Support\Facades\Queue::assertPushed(CanonicalIndexerJob::class);
    }

    /**
     * The other half: an identity the restored row DOES hold stays, and the
     * indexer rebuilds it — forced past its `(tenant, document, version_hash)`
     * idempotency key, because the restored version's hash is one it has
     * already indexed.
     */
    public function test_restoring_a_canonical_version_reindexes_it_instead_of_dropping_its_graph(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1zzz', 'archived', 'old canonical', canonical: true);
        $live = $this->makeVersion('v2aab', 'active', 'new plain body');
        KbNode::create([
            'node_uid' => 'dec-1', 'node_type' => 'decision', 'label' => 'Dec 1',
            'project_key' => 'eng', 'source_doc_id' => 'dec-1', 'payload_json' => [],
        ]);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.is_canonical', true);

        $this->assertSame('archived', $live->refresh()->status);
        $this->assertSame(
            1,
            KbNode::withoutGlobalScopes()->where('project_key', 'eng')->where('source_doc_id', 'dec-1')->count(),
            'the identity moved to the restored row, so its node is rebuilt rather than removed',
        );
        \Illuminate\Support\Facades\Queue::assertPushed(CanonicalIndexerJob::class, static fn (CanonicalIndexerJob $job): bool => $job->documentId === (int) $archived->id && $job->forceReindex);
    }

    /**
     * R10 — the reconstruction runs the SAME parser + validator the ingest
     * path runs, so a restore can never resurrect an identity ingestion would
     * have refused. A retained frontmatter with an invalid status degrades to
     * a non-canonical restore instead of writing a status no enum accepts.
     */
    public function test_frontmatter_the_parser_would_reject_does_not_come_back_as_a_canonical_identity(): void
    {
        $admin = $this->makeAdmin();
        $archived = $this->makeVersion('v1ppp', 'archived', 'old body', wasCanonical: true);
        KnowledgeDocument::withoutGlobalScopes()->whereKey($archived->id)->update([
            'frontmatter_json' => ['id' => 'dec-1', 'slug' => 'Dec 1 NOT A SLUG', 'type' => 'decision', 'status' => 'not-a-status'],
        ]);
        $this->makeVersion('v2qqq', 'active', 'new body');

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', false);

        $archived->refresh();
        $this->assertNull($archived->slug);
        $this->assertNull($archived->canonical_status, 'an invalid status never reaches the column');
        $this->assertFalse((bool) $archived->is_canonical);
    }

    /**
     * R10 §9 — a LEGACY canonical version (archived before the frontmatter was
     * persisted, so it retains `canonical_type` but no identity of its own)
     * carries the outgoing canonical version's identity: there the live row is
     * the only place the slug still exists, so leaving it unheld would lose it.
     * The transfer is audited with the displaced row on record.
     *
     * R21 note: the post-activation SWEEP branch that carries the identity off
     * a CONCURRENTLY activated row cannot be reached in this suite — it
     * requires a second transaction to activate a row between this one's
     * SELECT and its UPDATE, and the suite is single-process on SQLite. This
     * test therefore covers the ordinary `$live` path of the same rule; the
     * sweep is the same three lines, stated here rather than pretended
     * (the concurrency exception ADR 0030 already records).
     */
    public function test_restore_of_a_legacy_canonical_version_carries_the_outgoing_identity(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeVersion('v1fff', 'archived', 'old body', legacyCanonical: true);
        $this->makeVersion('v2ggg', 'archived', 'mid body');
        $concurrentlyActive = $this->makeVersion('v3hhh', 'active', 'concurrent body', canonical: true);

        $this->actingAs($admin)
            ->postJson("/api/admin/kb/documents/{$target->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.slug', 'dec-1');

        $target->refresh();
        $concurrentlyActive->refresh();
        $this->assertSame('active', $target->status);
        $this->assertTrue((bool) $target->is_canonical, 'the identity moved onto the restored version');
        $this->assertSame('dec-1', $target->slug);
        $this->assertSame('dec-1', $target->doc_id);
        $this->assertSame('archived', $concurrentlyActive->status);
        $this->assertFalse((bool) $concurrentlyActive->is_canonical, 'the swept row is vacated');
        $this->assertNull($concurrentlyActive->slug);
        $this->assertSame(1, KnowledgeDocument::query()->where('project_key', 'eng')->where('source_path', 'docs/dec.md')->where('slug', 'dec-1')->count(), 'the slug lives on exactly one row');

        $audit = \App\Models\KbCanonicalAudit::query()->where('project_key', 'eng')->where('event_type', 'updated')->latest('id')->first();
        $this->assertNotNull($audit, 'the identity transfer is audited');
        $this->assertSame('dec-1', $audit->slug);
        $this->assertSame([(int) $concurrentlyActive->id], $audit->before_json['displaced_ids']);
        $this->assertSame((int) $target->id, $audit->after_json['restored_version_id']);
    }

    /**
     * R3 / R27 — the timeline is bounded and paged on every surface: `total`
     * is the family size, `limit` / `offset` the page, `truncated` says when
     * the family holds more than the page reaches; the order is newest first
     * by `indexed_at` (strictly — the seeds have distinct timestamps) with
     * NULL `indexed_at` last on every driver; an invalid page is refused,
     * never silently satisfied.
     */
    public function test_the_timeline_is_bounded_and_paged_on_every_surface_and_says_so(): void
    {
        config(['kb.versioning.timeline_limit' => 2]);
        $admin = $this->makeAdmin();
        // makeVersion(): indexed_at = now - strlen(seed) minutes → a longer seed is OLDER.
        $oldest = $this->makeVersion('v1aaaaaa', 'archived', 'one');
        $middle = $this->makeVersion('v2bbbb', 'archived', 'two');
        $live = $this->makeVersion('v3', 'active', 'three');
        $never = $this->makeVersion('v0zzzzzzzz', 'archived', 'zero');
        $never->update(['indexed_at' => null]); // a row without indexed_at sorts LAST, whatever the driver

        $page1 = $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions")->assertOk();
        $this->assertSame([$live->id, $middle->id], array_column($page1->json('data'), 'id'), 'newest first, strictly by indexed_at');
        $this->assertSame(4, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.limit'));
        $this->assertSame(0, $page1->json('meta.offset'));
        $this->assertTrue($page1->json('meta.truncated'));

        $page2 = $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions?offset=2")->assertOk();
        $this->assertSame([$oldest->id, $never->id], array_column($page2->json('data'), 'id'), 'the second page continues the order; the NULL indexed_at row comes last');
        $this->assertSame(2, $page2->json('meta.offset'));
        $this->assertFalse($page2->json('meta.truncated'), 'the last page says the family is fully reached');

        $one = $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions?limit=1")->assertOk();
        $this->assertCount(1, $one->json('data'));
        $this->assertSame(1, $one->json('meta.limit'));
        $this->assertSame(2, $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions?limit=999")->json('meta.limit'), 'a request never exceeds the configured maximum');
        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions?limit=0")->assertStatus(422);
        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions?limit=abc")->assertStatus(422);
        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions?offset=-1")->assertStatus(422);

        $tool = new \App\Mcp\Tools\KbDocumentVersionsTool;
        $service = app(\App\Services\Kb\Versioning\DocumentVersionService::class);
        $payload = json_decode((string) $tool->handle(new \Laravel\Mcp\Request(['document_id' => $live->id, 'limit' => 1, 'offset' => 1]), $service, app(TenantContext::class))->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$middle->id], array_column($payload['versions'], 'id'));
        $this->assertSame(4, $payload['total']);
        $this->assertSame(1, $payload['limit']);
        $this->assertSame(1, $payload['offset']);
        $this->assertTrue($payload['truncated']);
        $this->assertTrue($tool->handle(new \Laravel\Mcp\Request(['document_id' => $live->id, 'limit' => 0]), $service, app(TenantContext::class))->isError(), 'an invalid page is refused on the MCP surface too');
        $this->assertTrue($tool->handle(new \Laravel\Mcp\Request(['document_id' => $live->id, 'limit' => '1.5']), $service, app(TenantContext::class))->isError(), 'a decimal is not an integer: refused, never truncated');
        $this->assertTrue($tool->handle(new \Laravel\Mcp\Request(['document_id' => $live->id, 'offset' => '1e2']), $service, app(TenantContext::class))->isError(), 'scientific notation is not an integer');
        $digits = json_decode((string) $tool->handle(new \Laravel\Mcp\Request(['document_id' => $live->id, 'limit' => '1']), $service, app(TenantContext::class))->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $digits['limit'], 'a string of digits is an integer');
        $this->assertTrue($tool->handle(new \Laravel\Mcp\Request(['document_id' => "{$live->id}.9"]), $service, app(TenantContext::class))->isError(), 'document_id is held to the same integer contract');

        $this->artisan('kb:doc-versions', ['document' => $live->id, '--tenant' => app(TenantContext::class)->current(), '--limit' => 1, '--offset' => 1])
            ->expectsOutputToContain('4 version(s)')
            ->expectsOutputToContain('Showing versions 2-2 of 4 (--limit, max 2; --offset to page).')
            ->assertExitCode(0);
        $this->artisan('kb:doc-versions', ['document' => $live->id, '--tenant' => app(TenantContext::class)->current(), '--limit' => 'abc'])
            ->expectsOutputToContain('--limit must be a positive integer.')
            ->assertExitCode(1);
        // `--diff` names versions of the FAMILY, whatever the listed page shows.
        $this->artisan('kb:doc-versions', ['document' => $live->id, '--tenant' => app(TenantContext::class)->current(), '--limit' => 1, '--diff' => "{$oldest->id}:{$live->id}"])
            ->expectsOutputToContain("diff #{$oldest->id}")
            ->assertExitCode(0);
    }

    /** SEC-SETTING-SHAPE-001 — a non-positive `timeline_limit` is the default bound (100), never "unbounded", and it warns. */
    public function test_a_non_positive_timeline_limit_is_the_default_bound_and_warns(): void
    {
        config(['kb.versioning.timeline_limit' => 0]);
        \Illuminate\Support\Facades\Log::spy();

        $this->assertSame(100, \App\Services\Kb\Versioning\DocumentVersionService::timelineLimit());
        $this->assertSame(5, \App\Services\Kb\Versioning\DocumentVersionService::timelineLimit(5));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->with(\Mockery::on(static fn (string $message): bool => str_contains($message, 'timeline_limit')), \Mockery::on(static fn (array $context): bool => ($context['default'] ?? null) === 100))
            ->atLeast()->once();
    }

    public function test_restoring_the_live_version_is_422(): void
    {
        $admin = $this->makeAdmin();
        $live = $this->makeVersion('v2bbb', 'active', 'body', canonical: true);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$live->id}/restore-version")
            ->assertStatus(422);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'v-'.uniqid().'@demo.local', 'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');
        $live = $this->makeVersion('v2bbb', 'active', 'body');

        $this->actingAs($viewer)->getJson("/api/admin/kb/documents/{$live->id}/versions")->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/kb/documents/1/versions')->assertStatus(401);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
