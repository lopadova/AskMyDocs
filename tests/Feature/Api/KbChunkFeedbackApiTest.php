<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\KbChunkFeedback;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class KbChunkFeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset TenantContext to the canonical default before each
        // test — other test classes in the suite mutate it via
        // ->set('foo'), and TenantContext is a singleton that can
        // leak between tests under Testbench. Pinning to 'default'
        // here keeps the chunk/doc fixtures (which hard-code
        // tenant_id='default') and the controller (which reads
        // TenantContext::current()) in agreement.
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
    }

    public function test_two_users_can_store_opposite_feedback_on_same_chunk(): void
    {
        [$doc, $chunk] = $this->seedChunk(projectKey: 'default');

        $alice = $this->makeAdmin('alice');
        $bob = $this->makeAdmin('bob');

        $this->actingAs($alice)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ])->assertOk()->assertJson([
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ]);

        $this->actingAs($bob)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertOk()->assertJson([
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ]);

        $this->assertDatabaseHas('kb_chunk_feedback', [
            'tenant_id' => 'default',
            'user_id' => $alice->id,
            'knowledge_chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ]);
        $this->assertDatabaseHas('kb_chunk_feedback', [
            'tenant_id' => 'default',
            'user_id' => $bob->id,
            'knowledge_chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ]);
    }

    /**
     * F1 (deep-review v8.0.1) — a user with NO project membership and
     * no global kb.read.any permission cannot feedback chunks even
     * when the chunk is in the active tenant.
     *
     * The controller answers 404 rather than 403 because the existing
     * AccessScopeScope on KnowledgeDocument hides documents the user
     * cannot read, so the chunk → document eager-load resolves to null
     * and the controller treats it as missing. 404 is also the
     * preferred posture for IDOR-like cases — it does not leak
     * existence of the protected resource. The key security invariant
     * is "no DB row persisted", which the assertDatabaseMissing below
     * pins down.
     */
    public function test_user_without_project_membership_cannot_feedback(): void
    {
        [, $chunk] = $this->seedChunk(projectKey: 'restricted');

        $stranger = $this->makeViewer('stranger');

        $this->actingAs($stranger)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertNotFound();

        $this->assertDatabaseMissing('kb_chunk_feedback', [
            'user_id' => $stranger->id,
            'knowledge_chunk_id' => $chunk->id,
        ]);
    }

    /**
     * F1 (deep-review v8.0.1) — a user with membership in project A
     * cannot feedback chunks belonging to project B in the same tenant.
     */
    public function test_user_with_membership_in_other_project_cannot_feedback_cross_project(): void
    {
        [, $chunkA] = $this->seedChunk(projectKey: 'project-a', source: 'a');
        [, $chunkB] = $this->seedChunk(projectKey: 'project-b', source: 'b');

        $userA = $this->makeViewer('user-a');
        ProjectMembership::create([
            'tenant_id' => 'default',
            'user_id' => $userA->id,
            'project_key' => 'project-a',
            'role' => 'member',
            'scope_allowlist' => [],
        ]);

        // Feedback on own project: allowed.
        $this->actingAs($userA)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunkA->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertOk();

        // Feedback on someone else's project: blocked even within the
        // same tenant. AccessScopeScope hides the foreign doc on
        // eager-load so the controller surfaces 404 (no existence
        // leak); the DB-missing assertion below is the load-bearing
        // security check.
        $this->actingAs($userA)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunkB->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertNotFound();

        $this->assertDatabaseMissing('kb_chunk_feedback', [
            'user_id' => $userA->id,
            'knowledge_chunk_id' => $chunkB->id,
        ]);
    }

    /**
     * F2 (deep-review v8.0.1) — repeating the same call (idempotent
     * double-click) MUST NOT throw a duplicate-key error or 500. The
     * upsert overwrites the prior signal and the database holds
     * exactly one row.
     */
    public function test_repeated_feedback_is_idempotent_and_updates_signal(): void
    {
        [, $chunk] = $this->seedChunk(projectKey: 'default');

        $user = $this->makeAdmin('user');

        $this->actingAs($user)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_SHOULD_HAVE_CITED,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertOk()->assertJson([
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ]);

        $this->assertSame(
            1,
            KbChunkFeedback::query()
                ->where('user_id', $user->id)
                ->where('knowledge_chunk_id', $chunk->id)
                ->count(),
            'upsert must keep exactly one row per (tenant_id, user_id, chunk_id)',
        );
        $this->assertDatabaseHas('kb_chunk_feedback', [
            'user_id' => $user->id,
            'knowledge_chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ]);
    }

    /**
     * F1-iter2 (PR #223 Copilot iter-2) — R30: a user with a
     * `project_memberships` row in tenant A must NOT be able to
     * feedback chunks in tenant B even when both tenants share the
     * same `project_key`. The existing `User::hasDocumentAccess()`
     * flow consults `project_memberships` without a `tenant_id`
     * predicate (correct for the read path, where the global scope
     * scopes by tenant separately), so the controller must enforce
     * tenant scoping explicitly on this WRITE path.
     *
     * Simulated by:
     *   - Active tenant = 'default'
     *   - Chunk + doc seeded in active tenant under `project=shared`
     *   - User membership stored under `tenant_id=other-tenant`, same
     *     `project=shared` and same user
     * The membership row would match `hasDocumentAccess()` (no tenant
     * filter) but the controller's explicit tenant-scoped check
     * refuses it.
     */
    public function test_membership_in_other_tenant_with_same_project_key_is_rejected(): void
    {
        [, $chunk] = $this->seedChunk(projectKey: 'shared');

        $user = $this->makeViewer('cross-tenant');
        // Membership in another tenant, same project_key, same user.
        ProjectMembership::create([
            'tenant_id' => 'other-tenant',
            'user_id' => $user->id,
            'project_key' => 'shared',
            'role' => 'member',
            'scope_allowlist' => [],
        ]);

        $this->actingAs($user)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertForbidden();

        $this->assertDatabaseMissing('kb_chunk_feedback', [
            'user_id' => $user->id,
            'knowledge_chunk_id' => $chunk->id,
        ]);
    }

    public function test_anonymous_request_is_rejected(): void
    {
        [, $chunk] = $this->seedChunk(projectKey: 'default');

        $this->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertUnauthorized();
    }

    public function test_invalid_signal_is_rejected(): void
    {
        [, $chunk] = $this->seedChunk(projectKey: 'default');

        $user = $this->makeAdmin('user');

        $this->actingAs($user)->postJson('/api/kb/feedback', [
            'chunk_id' => $chunk->id,
            'signal' => 'bogus',
        ])->assertStatus(422);
    }

    public function test_missing_chunk_returns_404(): void
    {
        $user = $this->makeAdmin('user');

        $this->actingAs($user)->postJson('/api/kb/feedback', [
            'chunk_id' => 999999,
            'signal' => KbChunkFeedback::SIGNAL_NOT_RELEVANT,
        ])->assertNotFound();
    }

    /**
     * @return array{0: KnowledgeDocument, 1: KnowledgeChunk}
     */
    private function seedChunk(string $projectKey, string $source = 'demo'): array
    {
        $doc = KnowledgeDocument::create([
            'tenant_id' => 'default',
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => "Doc {$source}",
            'source_path' => "docs/{$source}.md",
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', "doc-{$source}"),
            'version_hash' => hash('sha256', "doc-{$source}-v1"),
            'metadata' => [],
            'indexed_at' => now(),
        ]);

        $chunk = KnowledgeChunk::create([
            'tenant_id' => 'default',
            'knowledge_document_id' => $doc->id,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', "chunk-{$source}"),
            'chunk_text' => "Sample chunk {$source}",
            'metadata' => [],
            'embedding' => [0.1],
        ]);

        return [$doc, $chunk];
    }

    private function makeAdmin(string $label): User
    {
        $u = User::create([
            'name' => $label,
            'email' => $label.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('admin');

        return $u;
    }

    /**
     * A user with no kb.read.any permission. The dpo role has
     * `admin.access` + `logs.view` + `pii.detokenize` but intentionally
     * no `kb.*` global wildcard, which makes it the right role for
     * exercising the project-membership-only access path. Users without
     * a matching project_memberships row are denied at the controller's
     * hasDocumentAccess() gate.
     */
    private function makeViewer(string $label): User
    {
        $u = User::create([
            'name' => $label,
            'email' => $label.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('dpo');

        return $u;
    }
}
