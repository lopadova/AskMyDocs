<?php

namespace Tests\Feature\Api\Admin\Kb;

use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PR9 / Phase G2 — admin KB document detail endpoints.
 *
 * Mirrors the KbTreeControllerTest harness: routes mounted through
 * `defineRoutes()` so the Sanctum-less Testbench still wires the
 * `role` middleware; `RbacSeeder` in setUp; Cache::flush() to clear
 * Spatie's permission cache between rollback cycles.
 *
 * Every scenario hits the real DB + real Storage::fake('kb') (R4-safe).
 * The controller exercises the withTrashed() route-binding shim, so
 * the "show with ?with_trashed=1 returns a trashed doc" scenario
 * is the critical regression gate.
 */
class KbDocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::fake('kb');
    }

    // ------------------------------------------------------------------
    // show
    // ------------------------------------------------------------------

    public function test_show_returns_document_with_counts_and_top_20_audits(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        // Chunks aggregate — show should surface chunks_count.
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'c0'),
            'chunk_text' => 'chunk 0',
            'metadata' => [],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 1,
            'chunk_hash' => hash('sha256', 'c1'),
            'chunk_text' => 'chunk 1',
            'metadata' => [],
        ]);

        // 25 audits — show should surface top 20 in recent_audits and
        // the full 25 in audits_count.
        for ($i = 0; $i < 25; $i++) {
            KbCanonicalAudit::create([
                'project_key' => $doc->project_key,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'event_type' => 'updated',
                'actor' => 'test',
                'before_json' => ['i' => $i],
                'after_json' => ['i' => $i + 1],
                'metadata_json' => [],
                'created_at' => now()->subMinutes(25 - $i),
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id)
            ->assertOk();

        $response->assertJsonPath('data.id', $doc->id);
        $response->assertJsonPath('data.project_key', 'hr-portal');
        $response->assertJsonPath('data.slug', 'remote-work');
        $response->assertJsonPath('data.is_canonical', true);
        $response->assertJsonPath('data.chunks_count', 2);
        $response->assertJsonPath('data.audits_count', 25);

        $recent = $response->json('data.recent_audits');
        $this->assertIsArray($recent);
        $this->assertCount(20, $recent);
    }

    public function test_show_with_trashed_returns_soft_deleted_doc(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $doc->delete();

        // Default binding still resolves (withTrashed() shim) so the
        // admin UI can inspect the trashed row.
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'?with_trashed=1')
            ->assertOk();

        $response->assertJsonPath('data.id', $doc->id);
        $this->assertNotNull($response->json('data.deleted_at'));
    }

    // ------------------------------------------------------------------
    // raw
    // ------------------------------------------------------------------

    public function test_raw_returns_markdown_content_and_normalizes_path(): void
    {
        $admin = $this->makeAdmin();
        // Source path is already normalised at ingest time; write the
        // identical normalised key on the fake disk so the lookup
        // lines up (R1).
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        Storage::disk('kb')->put('policies/remote-work.md', "# Remote Work\n\nBody.");

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/raw')
            ->assertOk();

        $response->assertJsonPath('path', 'policies/remote-work.md');
        $response->assertJsonPath('disk', 'kb');
        $this->assertStringContainsString('# Remote Work', $response->json('content'));
        $this->assertSame(hash('sha256', $response->json('content')), $response->json('content_hash'));
    }

    public function test_raw_returns_404_when_file_missing(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/ghost.md', canonical: true, slug: 'ghost');
        // No Storage::put — file is missing on disk.

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/raw')
            ->assertStatus(404)
            ->assertJsonPath('path', 'policies/ghost.md');
    }

    // ------------------------------------------------------------------
    // download
    // ------------------------------------------------------------------

    public function test_download_streams_file(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        Storage::disk('kb')->put('policies/remote-work.md', "# DL\n");

        $response = $this->actingAs($admin)
            ->get('/api/admin/kb/documents/'.$doc->id.'/download');

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('remote-work.md', $disposition);
    }

    // ------------------------------------------------------------------
    // print
    // ------------------------------------------------------------------

    public function test_print_returns_html_with_print_id_and_title(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        Storage::disk('kb')->put('policies/remote-work.md', "# Printable body\n");

        $response = $this->actingAs($admin)
            ->get('/api/admin/kb/documents/'.$doc->id.'/print')
            ->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertStringContainsString('id="doc-print"', $body);
        $this->assertStringContainsString($doc->title, $body);
        $this->assertStringContainsString('Printable body', $body);
    }

    // ------------------------------------------------------------------
    // restore
    // ------------------------------------------------------------------

    public function test_restore_un_deletes_a_trashed_doc(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $doc->delete();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')
            ->assertOk();

        $response->assertJsonPath('data.id', $doc->id);
        $response->assertJsonPath('data.deleted_at', null);

        // Global scope now hides nothing — doc is back.
        $live = KnowledgeDocument::find($doc->id);
        $this->assertNotNull($live);
        $this->assertNull($live->deleted_at);
    }

    public function test_restore_on_live_doc_returns_409(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/live.md', canonical: true, slug: 'live');

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // destroy (soft + force)
    // ------------------------------------------------------------------

    public function test_destroy_soft_deletes_by_default(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/soft.md', canonical: true, slug: 'soft');
        Storage::disk('kb')->put('policies/soft.md', "# Body\n");

        $response = $this->actingAs($admin)
            ->deleteJson('/api/admin/kb/documents/'.$doc->id)
            ->assertOk();

        $this->assertSame('soft', $response->json('mode'));

        $doc->refresh();
        $this->assertNotNull($doc->deleted_at);
        $this->assertTrue(Storage::disk('kb')->exists('policies/soft.md'));
    }

    public function test_destroy_with_force_hard_deletes_and_removes_file(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/hard.md', canonical: true, slug: 'hard');
        $doc->metadata = ['disk' => 'kb', 'prefix' => ''];
        $doc->save();
        Storage::disk('kb')->put('policies/hard.md', "# Body\n");

        $response = $this->actingAs($admin)
            ->deleteJson('/api/admin/kb/documents/'.$doc->id.'?force=1')
            ->assertOk();

        $this->assertSame('hard', $response->json('mode'));

        // Row is gone from the DB (even through withTrashed()).
        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
        $this->assertFalse(Storage::disk('kb')->exists('policies/hard.md'));
    }

    // ------------------------------------------------------------------
    // history
    // ------------------------------------------------------------------

    public function test_history_paginated_and_ordered_desc_by_created_at(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/historical.md', canonical: true, slug: 'historical');

        // 25 audits with created_at staggered so the ORDER BY is
        // observable. Newest first (desc) — ids 25 down to 6 on page 1.
        for ($i = 0; $i < 25; $i++) {
            KbCanonicalAudit::create([
                'project_key' => $doc->project_key,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'event_type' => 'updated',
                'actor' => 'test',
                'before_json' => null,
                'after_json' => ['i' => $i],
                'metadata_json' => [],
                'created_at' => now()->subMinutes(25 - $i),
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/history')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertCount(20, $rows);

        // Copilot #6 fix: originally this test sampled only the first
        // and last row, which is ambiguous enough that the reviewer
        // read the assertion direction backwards. Switched to a full
        // pairwise walk — every row's created_at must be >= the next
        // one's — so descending order is enforced across the whole
        // page, not just its endpoints. A same-second tiebreaker on
        // id (desc) keeps the check stable for bulk inserts.
        $this->assertCount(
            20,
            array_filter(
                $rows,
                fn ($r) => isset($r['created_at']) && is_string($r['created_at']),
            ),
            'every row must carry a created_at timestamp',
        );
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $curr = $rows[$i]['created_at'];
            $next = $rows[$i + 1]['created_at'];
            $this->assertGreaterThanOrEqual(
                $next,
                $curr,
                "row {$i} ({$curr}) must be >= row ".($i + 1)." ({$next}) — history returns newest first",
            );
        }

        // Pagination envelope exists.
        $this->assertSame(25, $response->json('meta.total'));
    }

    // ------------------------------------------------------------------
    // updateRaw (PR10 / Phase G3)
    // ------------------------------------------------------------------

    public function test_update_raw_happy_writes_file_dispatches_ingest_job_and_audits(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        $newBody = "---\n"
            ."id: dec-remote\n"
            ."slug: remote-work\n"
            ."type: decision\n"
            ."status: accepted\n"
            ."---\n\n# Remote Work\n\nUpdated body.\n";

        $auditsBefore = KbCanonicalAudit::count();

        $response = $this->actingAs($admin)
            ->patchJson('/api/admin/kb/documents/'.$doc->id.'/raw', [
                'content' => $newBody,
            ])
            ->assertStatus(202);

        $response->assertJsonPath('queued', true);
        $this->assertIsInt($response->json('audit_id'));

        // File actually landed on disk (R4 — Storage::fake). Derive the
        // expected hash from what the server persisted to keep the
        // test robust against any transport-level newline normalisation.
        $this->assertTrue(Storage::disk('kb')->exists('policies/remote-work.md'));
        $onDisk = Storage::disk('kb')->get('policies/remote-work.md');
        $this->assertStringContainsString('# Remote Work', (string) $onDisk);
        $this->assertStringContainsString('type: decision', (string) $onDisk);
        $expectedHash = hash('sha256', (string) $onDisk);
        $response->assertJsonPath('version_hash', $expectedHash);

        // One new audit row with event_type=updated, stamped to the
        // canonical identifiers that survive hard deletes (R10).
        $this->assertSame($auditsBefore + 1, KbCanonicalAudit::count());
        $audit = KbCanonicalAudit::query()
            ->where('event_type', 'updated')
            ->where('project_key', 'hr-portal')
            ->where('slug', 'remote-work')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->email, $audit->actor);
        $this->assertSame($expectedHash, $audit->after_json['version_hash']);

        // Single ingestion execution path — job queued exactly once.
        Queue::assertPushed(IngestDocumentJob::class, 1);
    }

    public function test_update_raw_invalid_frontmatter_returns_422_and_does_not_dispatch_job(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        Storage::disk('kb')->put('policies/remote-work.md', "# stale\n");

        $auditsBefore = KbCanonicalAudit::count();

        $badBody = "---\n"
            ."id: dec-x\n"
            ."slug: remote-work\n"
            ."type: decision\n"
            ."status: NOT_A_STATUS\n"
            ."---\n\n# Body\n";

        $response = $this->actingAs($admin)
            ->patchJson('/api/admin/kb/documents/'.$doc->id.'/raw', [
                'content' => $badBody,
            ])
            ->assertStatus(422);

        // errors.frontmatter is keyed by the invalid frontmatter field
        // so the editor can surface per-key messages (R11).
        $response->assertJsonStructure([
            'errors' => [
                'frontmatter' => ['status'],
            ],
        ]);

        // File content did NOT change — disk write is gated on validation.
        $this->assertSame("# stale\n", Storage::disk('kb')->get('policies/remote-work.md'));
        // No audit, no job dispatch.
        $this->assertSame($auditsBefore, KbCanonicalAudit::count());
        Queue::assertNothingPushed();
    }

    public function test_update_raw_storage_failure_returns_500_and_does_not_dispatch_job_or_audit(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        // Override the fake disk with a Mockery spy that returns false
        // from `put()` — simulates an out-of-space / permission error.
        // Storage::fake() was already called in setUp(); replace the
        // kb disk with the spy for this scenario.
        $spy = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $spy->shouldReceive('put')->andReturn(false);
        Storage::set('kb', $spy);

        $auditsBefore = KbCanonicalAudit::count();

        $nonCanonicalBody = "# Plain update\n\nNo frontmatter — should still attempt to write.\n";

        $response = $this->actingAs($admin)
            ->patchJson('/api/admin/kb/documents/'.$doc->id.'/raw', [
                'content' => $nonCanonicalBody,
            ])
            ->assertStatus(500);

        $response->assertJsonPath('message', 'failed to write markdown to disk');

        // No audit and no job when the disk write fails (R4).
        $this->assertSame($auditsBefore, KbCanonicalAudit::count());
        Queue::assertNothingPushed();
    }

    public function test_update_raw_non_admin_returns_403(): void
    {
        $viewer = $this->makeViewer('viewer-g3');
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        $this->actingAs($viewer)
            ->patchJson('/api/admin/kb/documents/'.$doc->id.'/raw', [
                'content' => "# nope\n",
            ])
            ->assertStatus(403);
    }

    public function test_update_raw_guest_returns_401(): void
    {
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        $this->patchJson('/api/admin/kb/documents/'.$doc->id.'/raw', [
            'content' => "# nope\n",
        ])->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // RBAC — guest 401 + viewer 403 on all endpoints
    // ------------------------------------------------------------------

    public function test_guest_gets_401_on_all_endpoints(): void
    {
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        // Every endpoint is mounted under `auth:sanctum + role:…`. Using
        // getJson/postJson/deleteJson keeps `Accept: application/json` on
        // the request so the auth middleware returns 401 JSON instead
        // of redirecting to a non-existent `login` route (Laravel's
        // default behaviour for non-JSON requests).
        $this->getJson('/api/admin/kb/documents/'.$doc->id)->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/raw')->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/download')->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/print')->assertStatus(401);
        $this->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')->assertStatus(401);
        $this->deleteJson('/api/admin/kb/documents/'.$doc->id)->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/history')->assertStatus(401);
    }

    public function test_non_admin_gets_403_on_all_endpoints(): void
    {
        $viewer = $this->makeViewer('viewer-g2');
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        $this->actingAs($viewer)
            ->getJson('/api/admin/kb/documents/'.$doc->id)
            ->assertStatus(403);
        $this->actingAs($viewer)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')
            ->assertStatus(403);
        $this->actingAs($viewer)
            ->deleteJson('/api/admin/kb/documents/'.$doc->id)
            ->assertStatus(403);
        $this->actingAs($viewer)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/history')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // graph (PR11 / Phase G4)
    // ------------------------------------------------------------------

    public function test_graph_returns_empty_subgraph_for_raw_document(): void
    {
        $admin = $this->makeAdmin();
        // Raw doc: no canonical columns, no kb_nodes row exists for it.
        // The endpoint must return 200 + empty nodes/edges (NOT 404).
        $doc = $this->makeDoc('hr-portal', 'drafts/raw.md', canonical: false, slug: null);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/graph')
            ->assertOk();

        $response->assertJsonPath('nodes', []);
        $response->assertJsonPath('edges', []);
        $response->assertJsonPath('meta.project_key', 'hr-portal');
        $response->assertJsonPath('meta.center_node_uid', null);
    }

    public function test_graph_returns_canonical_subgraph_tenant_scoped(): void
    {
        $admin = $this->makeAdmin();

        // Two canonical docs in hr-portal + a tenant-scoped edge between
        // their kb_nodes rows. The graph endpoint is called for the first
        // doc — we expect the other hr-portal node + the edge to surface.
        $docA = $this->makeDoc('hr-portal', 'policies/remote.md', canonical: true, slug: 'remote-work');
        $docB = $this->makeDoc('hr-portal', 'policies/pto.md', canonical: true, slug: 'pto');

        $this->makeCanonicalNode('hr-portal', 'remote-work', 'policy', 'Remote Work', 'doc-remote-work');
        $this->makeCanonicalNode('hr-portal', 'pto', 'policy', 'PTO Policy', 'doc-pto');
        $this->makeEdge('hr-portal', 'remote-work', 'pto', 'related_to', 'edge-remote-pto', 0.9);

        // A node in a DIFFERENT tenant — must NOT leak into the response.
        // Uses the same `remote-work` slug as hr-portal to prove the
        // composite-FK tenant scoping (R10).
        $this->makeCanonicalNode('engineering', 'remote-work', 'runbook', 'Engineering Remote Runbook', 'doc-eng-remote');
        // No edge between engineering's node and anyone — but even if
        // there was one, the tenant filter would hide it.

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$docA->id.'/graph')
            ->assertOk();

        $response->assertJsonPath('meta.project_key', 'hr-portal');
        $response->assertJsonPath('meta.center_node_uid', 'remote-work');

        $nodes = $response->json('nodes');
        $this->assertIsArray($nodes);
        $uids = array_column($nodes, 'uid');
        $this->assertContains('remote-work', $uids);
        $this->assertContains('pto', $uids);
        // Engineering row must NOT be present even though the uid matches.
        // There must be NO engineering row — tenant filter is deterministic.
        foreach ($nodes as $node) {
            $this->assertNotSame('Engineering Remote Runbook', $node['label']);
            $this->assertNotSame('runbook', $node['type']);
        }

        // The single related_to edge round-trips with weight + provenance.
        $edges = $response->json('edges');
        $this->assertIsArray($edges);
        $this->assertCount(1, $edges);
        $this->assertSame('remote-work', $edges[0]['from']);
        $this->assertSame('pto', $edges[0]['to']);
        $this->assertSame('related_to', $edges[0]['type']);

        // Center node carries role='center'; neighbour role='neighbor'.
        $center = array_values(array_filter($nodes, fn ($n) => $n['uid'] === 'remote-work'))[0];
        $neighbor = array_values(array_filter($nodes, fn ($n) => $n['uid'] === 'pto'))[0];
        $this->assertSame('center', $center['role']);
        $this->assertSame('neighbor', $neighbor['role']);

        // Unused var reference — suppress static analysis warnings.
        unset($docB);
    }

    public function test_graph_non_admin_returns_403(): void
    {
        $viewer = $this->makeViewer('viewer-g4');
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        $this->actingAs($viewer)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/graph')
            ->assertStatus(403);
    }

    public function test_graph_guest_returns_401(): void
    {
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/graph')
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // exportPdf (PR11 / Phase G4)
    // ------------------------------------------------------------------

    public function test_export_pdf_returns_501_when_engine_is_disabled(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        Storage::disk('kb')->put('policies/remote-work.md', "# Remote Work\n\nBody.");

        // Default engine is 'disabled' — config/admin.php defaults to it
        // and the Testbench env does NOT set ADMIN_PDF_ENGINE.
        config()->set('admin.pdf_engine', 'disabled');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/export-pdf')
            ->assertStatus(501);

        // The message surfaces the actionable "enable ADMIN_PDF_ENGINE"
        // text so the SPA can echo it in a toast.
        $this->assertStringContainsString('PDF export disabled', (string) $response->json('message'));
        $response->assertJsonPath('engine', 'disabled');
    }

    public function test_export_pdf_returns_404_when_file_missing(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/ghost.md', canonical: true, slug: 'ghost');
        // No Storage::put — file missing on disk. The endpoint must 404
        // BEFORE it consults the renderer, so the 501 (engine disabled)
        // never shadows a genuine file-missing error.
        config()->set('admin.pdf_engine', 'disabled');

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/export-pdf')
            ->assertStatus(404)
            ->assertJsonPath('path', 'policies/ghost.md');
    }

    public function test_export_pdf_non_admin_returns_403(): void
    {
        $viewer = $this->makeViewer('viewer-g4-pdf');
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        $this->actingAs($viewer)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/export-pdf')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeViewer(string $slug, ?string $email = null): User
    {
        $user = User::create([
            'name' => $slug,
            'email' => $email ?? $slug.'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }

    private function makeCanonicalNode(
        string $projectKey,
        string $slug,
        string $type,
        string $label,
        string $sourceDocId,
    ): KbNode {
        return KbNode::create([
            'node_uid' => $slug,
            'node_type' => $type,
            'label' => $label,
            'project_key' => $projectKey,
            'source_doc_id' => $sourceDocId,
            'payload_json' => null,
        ]);
    }

    private function makeEdge(
        string $projectKey,
        string $fromUid,
        string $toUid,
        string $edgeType,
        string $edgeUid,
        float $weight = 1.0,
    ): KbEdge {
        return KbEdge::create([
            'edge_uid' => $edgeUid,
            'from_node_uid' => $fromUid,
            'to_node_uid' => $toUid,
            'edge_type' => $edgeType,
            'project_key' => $projectKey,
            'source_doc_id' => null,
            'weight' => $weight,
            'provenance' => 'wikilink',
            'payload_json' => null,
        ]);
    }

    private function makeDoc(
        string $projectKey,
        string $sourcePath,
        bool $canonical,
        ?string $slug,
    ): KnowledgeDocument {
        $docId = $canonical ? 'doc-'.($slug ?? 'x') : null;

        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'md',
            'title' => 'Title: '.basename($sourcePath, '.md'),
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $projectKey.'/'.$sourcePath),
            'version_hash' => hash('sha256', $projectKey.'/'.$sourcePath.'/v1'),
            'metadata' => [],
            'doc_id' => $docId,
            'slug' => $slug,
            'canonical_type' => $canonical ? 'policy' : null,
            'canonical_status' => $canonical ? 'accepted' : null,
            'is_canonical' => $canonical,
            'retrieval_priority' => $canonical ? 80 : 50,
            'indexed_at' => now(),
        ]);
    }
}
