<?php

namespace Tests\Feature\Rbac;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MultitenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_sees_documents_from_their_project(): void
    {
        [$userA, $userB] = $this->provisionTwoUsersAcrossTwoProjects();

        $docHr = $this->makeDoc('hr-portal', 'hr/policy.md');
        $docLegal = $this->makeDoc('legal-vault', 'legal/privacy.md');

        $this->actingAs($userA);
        $visibleForA = KnowledgeDocument::query()->get();

        $this->assertCount(1, $visibleForA);
        $this->assertSame($docHr->id, $visibleForA->first()->id);

        $this->actingAs($userB);
        $visibleForB = KnowledgeDocument::query()->get();

        $this->assertCount(1, $visibleForB);
        $this->assertSame($docLegal->id, $visibleForB->first()->id);
    }

    public function test_user_without_any_membership_sees_no_documents(): void
    {
        $this->makeDoc('hr-portal', 'hr/doc.md');

        $loner = $this->makeUser('loner@example.com');
        $this->actingAs($loner);

        $this->assertSame(0, KnowledgeDocument::query()->count());
    }

    public function test_user_with_kb_read_any_permission_sees_all_documents(): void
    {
        $this->makeDoc('hr-portal', 'hr/a.md');
        $this->makeDoc('legal-vault', 'legal/b.md');

        Permission::findOrCreate('kb.read.any', 'web');
        $role = Role::findOrCreate('super-admin', 'web');
        $role->syncPermissions(['kb.read.any']);

        $admin = $this->makeUser('root@example.com');
        $admin->assignRole('super-admin');

        $this->actingAs($admin);

        $this->assertSame(2, KnowledgeDocument::query()->count());
    }

    public function test_rbac_enforced_flag_disables_the_scope(): void
    {
        config()->set('rbac.enforced', false);

        $this->makeDoc('hr-portal', 'hr/a.md');
        $this->makeDoc('legal-vault', 'legal/b.md');

        $loner = $this->makeUser('loner2@example.com');
        $this->actingAs($loner);

        $this->assertSame(2, KnowledgeDocument::query()->count());
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function provisionTwoUsersAcrossTwoProjects(): array
    {
        $userA = $this->makeUser('a@example.com');
        ProjectMembership::create([
            'user_id' => $userA->id,
            'project_key' => 'hr-portal',
            'role' => 'member',
        ]);

        $userB = $this->makeUser('b@example.com');
        ProjectMembership::create([
            'user_id' => $userB->id,
            'project_key' => 'legal-vault',
            'role' => 'member',
        ]);

        return [$userA, $userB];
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
