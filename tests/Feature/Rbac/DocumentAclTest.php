<?php

namespace Tests\Feature\Rbac;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DocumentAclTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_project_membership_can_view_document_without_acl_rows(): void
    {
        [$user, $doc] = $this->provisionMemberWithDoc();

        $this->actingAs($user);

        $this->assertTrue($user->hasDocumentAccess($doc, 'view'));
        $this->assertSame(1, KnowledgeDocument::query()->count());
    }

    public function test_explicit_deny_acl_blocks_the_document_from_policy(): void
    {
        [$user, $doc] = $this->provisionMemberWithDoc();

        KnowledgeDocumentAcl::create([
            'knowledge_document_id' => $doc->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $user->id,
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_DENY,
        ]);

        $this->actingAs($user);

        $this->assertFalse($user->hasDocumentAccess($doc, 'view'));
    }

    public function test_explicit_deny_acl_also_excludes_the_row_from_the_global_scope(): void
    {
        [$user, $doc] = $this->provisionMemberWithDoc();

        KnowledgeDocumentAcl::create([
            'knowledge_document_id' => $doc->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $user->id,
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_DENY,
        ]);

        $this->actingAs($user);

        $this->assertSame(0, KnowledgeDocument::query()->count());
    }

    public function test_deny_wins_over_allow_when_both_exist_for_same_subject(): void
    {
        [$user, $doc] = $this->provisionMemberWithDoc();

        KnowledgeDocumentAcl::create([
            'knowledge_document_id' => $doc->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $user->id,
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_ALLOW,
        ]);

        KnowledgeDocumentAcl::create([
            'knowledge_document_id' => $doc->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $user->id,
            'permission' => KnowledgeDocumentAcl::PERMISSION_VIEW,
            'effect' => KnowledgeDocumentAcl::EFFECT_DENY,
        ]);

        $this->actingAs($user);

        $this->assertFalse($user->hasDocumentAccess($doc, 'view'));
    }

    public function test_scope_allowlist_folder_globs_restrict_visibility_within_project(): void
    {
        $user = $this->makeUser('restricted@example.com');

        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
            'scope_allowlist' => ['folder_globs' => ['hr/policies/*']],
        ]);

        $insideScope = $this->makeDoc('hr-portal', 'hr/policies/vacation.md');
        $outsideScope = $this->makeDoc('hr-portal', 'hr/contracts/nda.md');

        $this->actingAs($user);

        $this->assertTrue($user->hasDocumentAccess($insideScope, 'view'));
        $this->assertFalse($user->hasDocumentAccess($outsideScope, 'view'));
    }

    /**
     * @return array{0: User, 1: KnowledgeDocument}
     */
    private function provisionMemberWithDoc(): array
    {
        $user = $this->makeUser('member@example.com');
        ProjectMembership::create([
            'user_id' => $user->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
        ]);

        $doc = $this->makeDoc('hr-portal', 'hr/policy.md');

        return [$user, $doc];
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
        ]);
    }

    private function makeDoc(string $projectKey, string $sourcePath): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => basename($sourcePath),
            'source_path' => $sourcePath,
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $sourcePath.':v1'),
            'status' => 'indexed',
        ]);
    }
}
