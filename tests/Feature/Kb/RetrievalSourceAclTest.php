<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Access\SourceAclMirror;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;
use Tests\TestCase;

/**
 * ADR 0028 phase 2, and the third instance of the same shape.
 *
 * H8 was a role-deny ACL the global scope ignored. The v8.31 finding was a
 * scope allowlist the global scope ignored. Both were enforced by
 * `KnowledgeDocumentPolicy`, and both leaked, because the RAG hot path
 * resolves chunks through `whereHas('document')` and never calls the Gate.
 *
 * Mirrored source permissions are the same kind of arm, so they get the same
 * treatment: enforced in the global scope's SQL, and tested THROUGH the
 * relationship rather than through a controller. A controller test would
 * pass on the policy alone and prove nothing about grounding — which is
 * precisely how the previous two shipped.
 *
 * The scenario throughout is the one the ADR opens with: a file shared with
 * one person upstream, ingested into a project several people can read.
 */
final class RetrievalSourceAclTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED = 'drive/board/compensation-review.md';

    private const ORDINARY = 'drive/team/onboarding.md';

    private string $tenantId;

    private string $projectKey = 'default';

    private KnowledgeDocument $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();

        $this->shared = $this->makeDocumentWithChunk(
            self::SHARED,
            'Executive compensation review, board only.',
        );

        $this->makeDocumentWithChunk(self::ORDINARY, 'Welcome to the team.');
    }

    public function test_a_member_the_source_did_not_name_cannot_retrieve_the_chunks(): void
    {
        $insider = $this->member('insider@example.com');
        $outsider = $this->member('outsider@example.com');

        $this->mirror([SourcePrincipal::user('insider@example.com')]);

        $this->actingAs($outsider);

        // The exact builder shape KbSearchService uses. If the arm is not in
        // the scope's SQL, the compensation chunk becomes grounding text and
        // the model cites it.
        $texts = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->where('status', '!=', 'archived'))
            ->pluck('chunk_text')
            ->all();

        $this->assertCount(1, $texts, 'A member the source never named retrieved the restricted chunks.');
        $this->assertStringContainsString('Welcome to the team', $texts[0]);

        // And the person who WAS named still gets it, or the feature has
        // simply hidden the document from everybody.
        $this->actingAs($insider);

        $this->assertCount(
            2,
            KnowledgeChunk::query()->whereHas('document')->get(),
            'The person the source named lost access to their own document.',
        );
    }

    public function test_the_document_disappears_from_ordinary_listings_too(): void
    {
        $outsider = $this->member('outsider@example.com');
        $this->member('insider@example.com');

        $this->mirror([SourcePrincipal::user('insider@example.com')]);

        $this->actingAs($outsider);

        $this->assertSame(
            [self::ORDINARY],
            KnowledgeDocument::query()->pluck('source_path')->all(),
        );
    }

    public function test_revoking_the_share_upstream_revokes_it_here(): void
    {
        // The failure that makes a mirror worse than no mirror: permissions
        // that only ever accumulate look deliberate while being stale.
        $insider = $this->member('insider@example.com');

        $this->mirror([SourcePrincipal::user('insider@example.com')]);

        $this->actingAs($insider);
        $this->assertCount(2, KnowledgeDocument::query()->get(), 'Precondition: the insider can read it.');

        // Next sync: the source no longer names them.
        $this->mirror([SourcePrincipal::user('somebody-else@example.com')]);

        $this->actingAs($insider->fresh());

        $this->assertSame(
            [self::ORDINARY],
            KnowledgeDocument::query()->pluck('source_path')->all(),
            'An upstream un-share left the document granted here.',
        );
    }

    public function test_a_complete_empty_list_hides_the_document_from_the_project(): void
    {
        // "The source says nobody may read this" is a permission list, and
        // acting on it is the whole point of distinguishing it from unknown.
        $member = $this->member('member@example.com');

        $this->mirror([]);

        $this->actingAs($member);

        $this->assertSame(
            [self::ORDINARY],
            KnowledgeDocument::query()->pluck('source_path')->all(),
        );
    }

    public function test_an_incomplete_list_changes_nothing(): void
    {
        // A rate-limited or truncated response is not a permission list.
        // Restricting on one would revoke access because a request failed.
        $member = $this->member('member@example.com');

        $outcome = app(SourceAclMirror::class)->syncFor(
            $this->shared,
            SourceAccess::unknown([SourcePrincipal::user('someone@example.com')]),
        );

        $this->assertTrue($outcome->skipped);
        $this->assertSame('incomplete', $outcome->reason);

        $this->actingAs($member);

        $this->assertCount(
            2,
            KnowledgeDocument::query()->get(),
            'An unknown permission list restricted a document.',
        );
    }

    public function test_a_manual_grant_is_enough_on_its_own(): void
    {
        // How an operator answers a triage entry: an ordinary ACL row. It has
        // to work even though the source has never named the person, or the
        // queue would be a list of questions with no way to answer them.
        $member = $this->member('member@example.com');

        $this->mirror([SourcePrincipal::user('nobody-here@example.com')]);

        KnowledgeDocumentAcl::create([
            'tenant_id' => $this->tenantId,
            'knowledge_document_id' => $this->shared->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $member->id,
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_ALLOW,
            'origin' => KnowledgeDocumentAcl::ORIGIN_MANUAL,
        ]);

        $this->actingAs($member->fresh());

        $this->assertCount(
            2,
            KnowledgeDocument::query()->get(),
            'A manual grant did not survive the source restriction.',
        );
    }

    public function test_a_manual_grant_survives_the_next_sync(): void
    {
        // Reconciliation owns mirrored rows and must not touch manual ones:
        // an operator's decision is not the source's to withdraw.
        $member = $this->member('member@example.com');

        $this->mirror([SourcePrincipal::user('nobody-here@example.com')]);

        KnowledgeDocumentAcl::create([
            'tenant_id' => $this->tenantId,
            'knowledge_document_id' => $this->shared->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $member->id,
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_ALLOW,
            'origin' => KnowledgeDocumentAcl::ORIGIN_MANUAL,
        ]);

        $this->mirror([SourcePrincipal::user('still-nobody@example.com')]);

        $this->actingAs($member->fresh());

        $this->assertCount(
            2,
            KnowledgeDocument::query()->get(),
            'Reconciliation deleted a manual grant.',
        );
    }

    public function test_documents_nobody_mirrored_are_untouched(): void
    {
        // The rule must cost nothing for every corpus whose connectors do not
        // report permissions, which today is all of them.
        $member = $this->member('member@example.com');

        $this->actingAs($member);

        $this->assertCount(2, KnowledgeDocument::query()->get());
        $this->assertCount(2, KnowledgeChunk::query()->whereHas('document')->get());
    }

    public function test_the_policy_and_the_sql_agree(): void
    {
        // R33's actual requirement: the SQL must not be wider than
        // User::hasDocumentAccess(), which stays authoritative. Disagreement
        // in either direction is the bug — a policy that permits what the
        // scope hides is confusing, one that hides what the scope permits is
        // a leak.
        $insider = $this->member('insider@example.com');
        $outsider = $this->member('outsider@example.com');

        $this->mirror([SourcePrincipal::user('insider@example.com')]);

        $doc = KnowledgeDocument::withoutGlobalScopes()->find($this->shared->id);

        $this->assertTrue($insider->fresh()->hasDocumentAccess($doc));
        $this->assertFalse($outsider->fresh()->hasDocumentAccess($doc));

        $this->actingAs($outsider->fresh());
        $this->assertNull(KnowledgeDocument::query()->find($this->shared->id));

        $this->actingAs($insider->fresh());
        $this->assertNotNull(KnowledgeDocument::query()->find($this->shared->id));
    }

    /**
     * @param  list<SourcePrincipal>  $principals
     */
    private function mirror(array $principals): void
    {
        app(SourceAclMirror::class)->syncFor(
            $this->shared,
            SourceAccess::of($principals),
        );
    }

    private function member(string $email): User
    {
        $user = User::create([
            'name' => 'Member',
            'email' => $email,
            'password' => bcrypt('secret-secret'),
        ]);

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $this->projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $user->fresh();
    }

    private function makeDocumentWithChunk(string $sourcePath, string $text): KnowledgeDocument
    {
        $doc = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'source_type' => 'connector',
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
}
