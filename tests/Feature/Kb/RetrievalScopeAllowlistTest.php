<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H8's sibling.
 *
 * `AccessScopeScope` deliberately enforced only two of the four arms of
 * `User::hasDocumentAccess()` — the project constraint and deny-ACL rows —
 * and delegated the `scope_allowlist` arm (folder_globs / tags) to
 * `KnowledgeDocumentPolicy::view()`. That delegation holds for controller
 * reads, which call the Gate. It does not hold for retrieval: the RAG hot
 * path resolves chunks through `whereHas('document')` and never consults
 * the policy, so a membership scoped to `hr/policies/**` still retrieved
 * chunks from `hr/salaries/**` and fed them to the model as grounding.
 *
 * That is the same shape as H8 — "only caught by the per-row policy check,
 * which the hot retrieval path skips" — one arm later.
 *
 * These tests assert the scope, not the pgvector query: SQLite cannot parse
 * `embedding <=> ?::vector`, so they exercise the exact builder shape the
 * retrieval path uses (`KnowledgeChunk::whereHas('document')`) plus the
 * document query every bulk read goes through.
 */
final class RetrievalScopeAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private const IN_SCOPE = 'hr/policies/remote-work.md';

    private const OUT_OF_SCOPE = 'hr/salaries/exec-comp.md';

    private string $tenantId;

    private string $projectKey = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();

        $this->makeDocumentWithChunk(self::IN_SCOPE, 'Remote work is allowed on Fridays.');
        $this->makeDocumentWithChunk(self::OUT_OF_SCOPE, 'The CFO base salary is 250000 EUR.');
    }

    public function test_scoped_membership_cannot_read_documents_outside_its_folder_globs(): void
    {
        $this->actingAs($this->scopedUser(['folder_globs' => ['hr/policies/**']]));

        $paths = KnowledgeDocument::query()->pluck('source_path')->all();

        $this->assertSame([self::IN_SCOPE], $paths);
    }

    public function test_scoped_membership_cannot_retrieve_chunks_outside_its_folder_globs(): void
    {
        $this->actingAs($this->scopedUser(['folder_globs' => ['hr/policies/**']]));

        // The exact builder shape KbSearchService uses for both the semantic
        // and the runner-up branch. If the scope does not reach through the
        // relationship subquery, the salary chunk becomes grounding text.
        $texts = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
            ->pluck('chunk_text')
            ->all();

        $this->assertCount(1, $texts, 'A scoped user retrieved chunks from outside their allowlist.');
        $this->assertStringContainsString('Remote work', $texts[0]);
    }

    public function test_single_level_glob_does_not_leak_nested_paths(): void
    {
        // R19/H4 semantics must survive the push into SQL: `*` stays inside
        // one segment. `hr/*` grants hr/handbook.md, never hr/salaries/x.md.
        $this->makeDocumentWithChunk('hr/handbook.md', 'Welcome to the company.');

        $this->actingAs($this->scopedUser(['folder_globs' => ['hr/*']]));

        $paths = KnowledgeDocument::query()->pluck('source_path')->all();

        $this->assertSame(['hr/handbook.md'], $paths);
    }

    public function test_tag_arm_of_the_allowlist_still_grants_access(): void
    {
        // `matchesScope()` is globs OR tags — a doc outside every glob is
        // still readable when it carries an allowlisted tag. Pushing the
        // globs into SQL must not silently drop that arm.
        $this->tagDocument(self::OUT_OF_SCOPE, 'board-approved');

        $this->actingAs($this->scopedUser([
            'folder_globs' => ['hr/policies/**'],
            'tags' => ['board-approved'],
        ]));

        $paths = KnowledgeDocument::query()->pluck('source_path')->all();

        sort($paths);

        $this->assertSame([self::IN_SCOPE, self::OUT_OF_SCOPE], $paths);
    }

    public function test_membership_without_an_allowlist_is_unrestricted_within_its_project(): void
    {
        // The no-scope case must stay byte-identical: an empty allowlist
        // means "no further restriction", not "deny everything".
        $this->actingAs($this->scopedUser([]));

        $this->assertCount(2, KnowledgeDocument::query()->get());
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function scopedUser(array $scopeAllowlist): User
    {
        $user = User::create([
            'name' => 'Scoped Reader',
            'email' => 'scoped-'.uniqid().'@example.test',
            'password' => bcrypt('secret-secret'),
        ]);

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $this->projectKey,
            'role' => 'member',
            'scope_allowlist' => $scopeAllowlist === [] ? null : $scopeAllowlist,
        ]);

        return $user->fresh();
    }

    private function makeDocumentWithChunk(string $sourcePath, string $text): KnowledgeDocument
    {
        $doc = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'source_type' => 'upload',
            'title' => basename($sourcePath),
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $text),
        ]);

        KnowledgeChunk::create([
            'tenant_id' => $this->tenantId,
            'knowledge_document_id' => $doc->id,
            'project_key' => $this->projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $text),
            'heading_path' => '',
            'chunk_text' => $text,
        ]);

        return $doc;
    }

    private function tagDocument(string $sourcePath, string $slug): void
    {
        $doc = KnowledgeDocument::withoutGlobalScopes()
            ->where('source_path', $sourcePath)
            ->firstOrFail();

        $tagId = \DB::table('kb_tags')->insertGetId([
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'slug' => $slug,
            'label' => $slug,
        ]);

        \DB::table('knowledge_document_tags')->insert([
            'knowledge_document_id' => $doc->id,
            'kb_tag_id' => $tagId,
        ]);
    }
}
