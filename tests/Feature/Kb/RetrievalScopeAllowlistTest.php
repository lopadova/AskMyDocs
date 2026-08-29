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

    public function test_glob_mixing_cross_and_single_segment_wildcards_grants_nothing(): void
    {
        // A glob carrying BOTH a cross-segment and a single-segment wildcard
        // has no exact LIKE translation: the whole-string separator count
        // that pins a single-segment wildcard cannot coexist with a
        // cross-segment one. Translating it to the literal prefix before the
        // first wildcard would WIDEN the query — every sibling folder under
        // `hr/` would match `hr/%`, salaries included — and on the retrieval
        // path nothing narrows it afterwards, so those chunks would become
        // grounding. ScopeAllowlistSql therefore grants nothing for the
        // shape; this test pins BOTH halves of that contract.
        $this->makeDocumentWithChunk('hr/eng/reports/q1.md', 'Engineering shipped 12 features.');

        $this->actingAs($this->scopedUser(['folder_globs' => ['hr/*/reports/**']]));

        $paths = KnowledgeDocument::query()->pluck('source_path')->all();

        $this->assertNotContains(
            self::OUT_OF_SCOPE,
            $paths,
            'An inexpressible glob widened the scope to a sibling folder.',
        );
        $this->assertSame(
            [],
            $paths,
            'An inexpressible glob must grant nothing rather than a superset.',
        );

        // The retrieval shape must fail closed identically — that is the
        // path with no policy behind it.
        $texts = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
            ->pluck('chunk_text')
            ->all();

        $this->assertSame([], $texts);
    }

    public function test_exact_globs_still_grant_when_listed_beside_an_inexpressible_one(): void
    {
        // Failing closed is per GLOB, not per membership: an allowlist that
        // mixes an expressible glob with an inexpressible one must still
        // grant everything the expressible one covers.
        $this->actingAs($this->scopedUser([
            'folder_globs' => ['hr/*/reports/**', 'hr/policies/**'],
        ]));

        $paths = KnowledgeDocument::query()->pluck('source_path')->all();

        $this->assertSame([self::IN_SCOPE], $paths);
    }

    public function test_memberships_without_an_allowlist_collapse_into_one_in_clause(): void
    {
        // R33 promises a subject with no restriction of this kind generates
        // the identical query as before. Emitting one OR arm per project
        // regardless would quietly break that for the common subject who
        // holds several memberships and no allowlist at all.
        $user = $this->scopedUser([]);
        $this->addMembership($user, 'engineering', []);
        $this->addMembership($user, 'marketing', []);

        $this->actingAs($user->fresh());

        $sql = KnowledgeDocument::query()->toSql();

        $this->assertStringContainsString('project_key" in (', $sql);
        $this->assertStringNotContainsString(
            'project_key" = ?',
            $sql,
            'Unscoped memberships were expanded into an OR chain instead of an IN list.',
        );
    }

    public function test_a_scoped_project_does_not_constrain_an_unscoped_sibling(): void
    {
        // The allowlist is per membership. A scope held in one project must
        // never reach across into another project the subject joined without
        // one, and the unscoped project must stay fully readable.
        $this->makeDocumentWithChunk('reports/roadmap.md', 'Roadmap for next quarter.', 'engineering');

        $user = $this->scopedUser(['folder_globs' => ['hr/policies/**']]);
        $this->addMembership($user, 'engineering', []);

        $this->actingAs($user->fresh());

        $paths = KnowledgeDocument::query()->pluck('source_path')->all();
        sort($paths);

        $this->assertSame(['hr/policies/remote-work.md', 'reports/roadmap.md'], $paths);
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

    private function makeDocumentWithChunk(
        string $sourcePath,
        string $text,
        ?string $projectKey = null,
    ): KnowledgeDocument {
        $projectKey ??= $this->projectKey;

        $doc = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => $projectKey,
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
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $text),
            'heading_path' => '',
            'chunk_text' => $text,
        ]);

        return $doc;
    }

    private function addMembership(User $user, string $projectKey, array $scopeAllowlist): void
    {
        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
            'scope_allowlist' => $scopeAllowlist === [] ? null : $scopeAllowlist,
        ]);
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

        // R30/R31 — the pivot is tenant-aware, and the suite pins
        // TenantContext to a non-default tenant. Letting the column fall back
        // to its 'default' DB default would seed a cross-tenant pivot row.
        \DB::table('knowledge_document_tags')->insert([
            'tenant_id' => $this->tenantId,
            'knowledge_document_id' => $doc->id,
            'kb_tag_id' => $tagId,
        ]);
    }
}
