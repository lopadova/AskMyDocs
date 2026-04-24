<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo seeder for E2E + local dev.
 *
 *  - One super-admin `admin@demo.local` (password: `password`)
 *  - Three KnowledgeDocument rows (hr-portal + engineering) with canonical
 *    metadata + a single chunk each, so the WikilinkResolver returns a
 *    realistic preview in Playwright tests.
 *  - A welcome Conversation for the demo admin so the sidebar renders a
 *    populated state on first load.
 *
 * Idempotent: run repeatedly without duplicating rows. Depends on
 * RbacSeeder for role definitions (also seeded on the fly if missing).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Guarantee roles/permissions exist before we assign them.
        if (Role::query()->count() === 0) {
            $this->call(RbacSeeder::class);
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.local'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
            ],
        );

        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }

        $this->seedProjectMemberships($admin);
        $this->seedKnowledgeDocuments();
        $this->seedConversations($admin);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedProjectMemberships(User $admin): void
    {
        foreach (['hr-portal', 'engineering'] as $projectKey) {
            ProjectMembership::firstOrCreate(
                ['user_id' => $admin->id, 'project_key' => $projectKey],
                ['role' => 'member', 'scope_allowlist' => null],
            );
        }
    }

    private function seedKnowledgeDocuments(): void
    {
        $this->upsertDoc(
            projectKey: 'hr-portal',
            slug: 'remote-work-policy',
            title: 'Remote Work Policy',
            sourcePath: 'policies/remote-work-policy.md',
            canonicalType: 'policy',
            preview: 'ACME employees may work remotely up to 3 days per week with manager approval. Full remote arrangements require VP sign-off and are reviewed annually.',
        );

        $this->upsertDoc(
            projectKey: 'hr-portal',
            slug: 'pto-guidelines',
            title: 'PTO Guidelines',
            sourcePath: 'benefits/pto-guidelines.md',
            canonicalType: 'policy',
            preview: 'Employees accrue 2 days PTO per month. Requests of 3+ consecutive days require manager approval 14 days in advance.',
        );

        $this->upsertDoc(
            projectKey: 'engineering',
            slug: 'incident-response',
            title: 'Incident Response Runbook',
            sourcePath: 'runbooks/incident-response.md',
            canonicalType: 'runbook',
            preview: 'Step 1: page on-call via /alert. Step 2: open the #incident channel. Step 3: declare severity. Postmortem due within 5 business days.',
        );
    }

    private function upsertDoc(
        string $projectKey,
        string $slug,
        string $title,
        string $sourcePath,
        string $canonicalType,
        string $preview,
    ): void {
        $doc = KnowledgeDocument::withoutGlobalScopes()
            ->where('project_key', $projectKey)
            ->where('slug', $slug)
            ->first();

        if ($doc === null) {
            $doc = KnowledgeDocument::create([
                'project_key' => $projectKey,
                'source_type' => 'md',
                'title' => $title,
                'source_path' => $sourcePath,
                'mime_type' => 'text/markdown',
                'language' => 'en',
                'access_scope' => 'project',
                'status' => 'indexed',
                'document_hash' => hash('sha256', $projectKey.'/'.$slug),
                'version_hash' => hash('sha256', $projectKey.'/'.$slug.'/v1'),
                'metadata' => [],
                'slug' => $slug,
                'canonical_type' => $canonicalType,
                'canonical_status' => 'accepted',
                'is_canonical' => true,
                'retrieval_priority' => 80,
            ]);
        }

        if ($doc->chunks()->count() > 0) {
            return;
        }

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $preview),
            'heading_path' => 'Intro',
            'chunk_text' => $preview,
            'metadata' => [],
        ]);
    }

    private function seedConversations(User $admin): void
    {
        if ($admin->conversations()->count() > 0) {
            return;
        }

        Conversation::create([
            'user_id' => $admin->id,
            'title' => 'Welcome — remote work questions',
            'project_key' => 'hr-portal',
        ]);
    }
}
