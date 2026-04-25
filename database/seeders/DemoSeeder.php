<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\KbPath;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Demo seeder for E2E + local dev.
 *
 *  - One admin `admin@demo.local` (password: `password`) — has
 *    `commands.run` but NOT `commands.destructive`. The
 *    super-destructive flows go through `super@demo.local`.
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

        // admin@demo.local is the regular admin user used by the
        // chromium project — has `commands.run` but NOT
        // `commands.destructive`. That's load-bearing for
        // admin-maintenance.spec.ts:62 which expects 403 when admin
        // tries to preview a destructive command. The dedicated
        // super@demo.local (seeded below) is the destructive-flow
        // operator; making BOTH super-admin would break the RBAC
        // gradient the suite is testing.
        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        // Viewer account for the Playwright chromium-viewer project
        // (PR6 Phase F1). Seeded here — not as its own seeder — so every
        // E2E run has a known-quantity viewer available without needing
        // a separate `/testing/seed` call.
        $viewer = User::firstOrCreate(
            ['email' => 'viewer@demo.local'],
            [
                'name' => 'Demo Viewer',
                'password' => Hash::make('password'),
            ],
        );
        if (! $viewer->hasRole('viewer')) {
            $viewer->assignRole('viewer');
        }

        // PR13 / Phase H2 — super-admin account for the Playwright
        // super-admin project. Needed to exercise destructive
        // maintenance commands (kb:prune-deleted etc.) which require
        // the `commands.destructive` permission — a super-admin-only
        // permission per config/admin.php.
        //
        // Note: `admin@demo.local` already has super-admin above, but
        // we seed a DEDICATED account here so the Playwright storage
        // state for super-admin scenarios is distinct from the
        // admin/viewer ones. Keeps the RBAC isolation clean.
        $super = User::firstOrCreate(
            ['email' => 'super@demo.local'],
            [
                'name' => 'Demo Super-Admin',
                'password' => Hash::make('password'),
            ],
        );
        if (! $super->hasRole('super-admin')) {
            $super->assignRole('super-admin');
        }

        $this->seedProjectMemberships($admin);
        $this->seedProjectMemberships($viewer);
        $this->seedProjectMemberships($super);
        $this->seedKnowledgeDocuments();
        $this->seedCanonicalGraph();
        $this->seedConversations($admin);
        $this->seedChatLogs($admin);

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
            sourcePath: 'policies/pto-guidelines.md',
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
                // Phase G4 — persist the stable canonical identifier on
                // the row so the /graph endpoint can resolve the seed
                // node via source_doc_id (preferred path). The frontmatter
                // also carries `id: demo-{slug}` so CanonicalIndexerJob
                // (when a real worker runs) would produce the same value.
                'doc_id' => 'demo-'.$slug,
                'slug' => $slug,
                'canonical_type' => $canonicalType,
                'canonical_status' => 'accepted',
                'is_canonical' => true,
                'retrieval_priority' => 80,
            ]);
        }

        // Write the markdown body to the KB disk so the G2 Preview tab
        // can fetch it through `/api/admin/kb/documents/{id}/raw`.
        // Idempotent: Storage::put() overwrites, which is what we want
        // when the seeder is re-run against an already-populated disk.
        $this->writeMarkdownForDoc($projectKey, $sourcePath, $title, $canonicalType, $preview);

        // Seed a single canonical audit row per doc so the History tab
        // has something to render on first open. Idempotent via the
        // composite `(project_key, slug, event_type)` uniqueness check.
        $this->seedPromotionAudit($projectKey, $slug, $canonicalType);

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

    /**
     * Seed a handful of ChatLog rows so the admin dashboard KPIs +
     * charts render populated on first load. Without this, the
     * Playwright happy-path spec would have to wait for a real chat
     * round-trip before any tile leaves the `empty` state.
     *
     * Idempotent: no-op when rows already exist for this seed session.
     */
    private function seedChatLogs(User $admin): void
    {
        if (\App\Models\ChatLog::query()->count() > 0) {
            return;
        }

        $now = \Illuminate\Support\Carbon::now();
        $samples = [
            ['hr-portal', 'openai', 'gpt-4o', 1200, 120, 240],
            ['hr-portal', 'openai', 'gpt-4o', 900, 90, 180],
            ['hr-portal', 'anthropic', 'claude-3-5-sonnet', 1500, 110, 200],
            ['engineering', 'openai', 'gpt-4o', 800, 80, 160],
            ['engineering', 'anthropic', 'claude-3-5-sonnet', 1100, 95, 190],
        ];

        foreach ($samples as $idx => [$project, $provider, $model, $latency, $promptTokens, $completionTokens]) {
            \App\Models\ChatLog::create([
                'session_id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $admin->id,
                'question' => 'Demo question #'.($idx + 1),
                'answer' => 'Demo answer.',
                'project_key' => $project,
                'ai_provider' => $provider,
                'ai_model' => $model,
                'chunks_count' => 3,
                'sources' => [],
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
                'latency_ms' => $latency,
                'created_at' => $now->copy()->subHours($idx)->toDateTimeString(),
            ]);
        }
    }

    /**
     * Write the canonical markdown body to the KB disk so the G2
     * Preview tab can fetch it via `/api/admin/kb/documents/{id}/raw`.
     * Uses the same frontmatter fence the CanonicalParser expects, so
     * a re-ingest through `kb:ingest-folder` would keep the row
     * canonical — no drift between the seeded DB state and the
     * markdown-as-source-of-truth invariant (CLAUDE.md §6).
     */
    private function writeMarkdownForDoc(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $canonicalType,
        string $preview,
    ): void {
        $disk = (string) config('kb.sources.disk', 'kb');
        $prefix = trim((string) config('kb.sources.path_prefix', ''), '/');
        // Copilot #3 fix (R1 + R4): every disk write must go through
        // `KbPath::normalize()` so we collapse `//`, strip accidental
        // leading slashes, and reject `.`/`..` segments — the same
        // contract every other KB writer honours. The raw
        // concatenation used to let `policies//remote.md` through
        // (broken key on S3, silent near-miss on local).
        $rawPath = $prefix === '' ? $sourcePath : $prefix.'/'.$sourcePath;
        $fullPath = KbPath::normalize($rawPath);

        $slug = pathinfo($sourcePath, PATHINFO_FILENAME);
        $fm = "---\n"
            ."id: demo-{$slug}\n"
            ."type: {$canonicalType}\n"
            ."status: accepted\n"
            ."project: {$projectKey}\n"
            ."---\n\n";
        $body = "# {$title}\n\n{$preview}\n";

        // R4: `Storage::put()` returns false on failure. Surface it:
        // log loudly and throw — a silent seeder-level write failure
        // produces a DB row that claims to be canonical while there
        // is no markdown on disk for the admin UI or ingest to read.
        $ok = Storage::disk($disk)->put($fullPath, $fm.$body);
        if ($ok === false) {
            $message = sprintf(
                'DemoSeeder: failed to write seeded markdown to disk "%s" path "%s".',
                $disk,
                $fullPath,
            );
            Log::error($message);
            throw new RuntimeException($message);
        }
    }

    /**
     * Seed a tiny canonical graph (Phase G4): one kb_nodes row per
     * canonical doc + one kb_edges row linking the two hr-portal
     * policies. Lets the admin KB Graph tab render a real subgraph
     * in local dev + E2E without waiting for CanonicalIndexerJob
     * to process the seeded markdown through a real queue worker.
     *
     * Idempotent: each node/edge is upserted on (project_key,
     * node_uid) / (project_key, edge_uid) which mirrors the DB
     * composite unique constraints.
     */
    private function seedCanonicalGraph(): void
    {
        $nodes = [
            ['hr-portal', 'remote-work-policy', 'policy', 'Remote Work Policy', 'demo-remote-work-policy'],
            ['hr-portal', 'pto-guidelines', 'policy', 'PTO Guidelines', 'demo-pto-guidelines'],
            ['engineering', 'incident-response', 'runbook', 'Incident Response Runbook', 'demo-incident-response'],
        ];

        foreach ($nodes as [$project, $slug, $type, $label, $sourceDocId]) {
            KbNode::updateOrCreate(
                ['project_key' => $project, 'node_uid' => $slug],
                [
                    'node_type' => $type,
                    'label' => $label,
                    'source_doc_id' => $sourceDocId,
                    'payload_json' => null,
                ],
            );
        }

        // Link the two hr-portal policies with a `related_to` edge so
        // the Graph tab on remote-work-policy shows exactly one
        // neighbour + one edge — enough to exercise the full render
        // path without overwhelming the radial layout.
        KbEdge::updateOrCreate(
            ['project_key' => 'hr-portal', 'edge_uid' => 'demo-edge-remote-pto'],
            [
                'from_node_uid' => 'remote-work-policy',
                'to_node_uid' => 'pto-guidelines',
                'edge_type' => 'related_to',
                'source_doc_id' => 'demo-remote-work-policy',
                'weight' => 0.9,
                'provenance' => 'wikilink',
                'payload_json' => null,
            ],
        );
    }

    /**
     * Seed one canonical audit row per doc so the G2 History tab has
     * something to render on first open. Idempotent: only inserts if
     * no `promoted` audit already exists for (project, slug).
     */
    private function seedPromotionAudit(string $projectKey, string $slug, string $canonicalType): void
    {
        $exists = KbCanonicalAudit::query()
            ->where('project_key', $projectKey)
            ->where('slug', $slug)
            ->where('event_type', 'promoted')
            ->exists();
        if ($exists) {
            return;
        }

        KbCanonicalAudit::create([
            'project_key' => $projectKey,
            'doc_id' => 'demo-'.$slug,
            'slug' => $slug,
            'event_type' => 'promoted',
            'actor' => 'demo-seeder',
            'before_json' => null,
            'after_json' => [
                'canonical_type' => $canonicalType,
                'canonical_status' => 'accepted',
            ],
            'metadata_json' => ['source' => 'DemoSeeder'],
            'created_at' => now(),
        ]);
    }
}
