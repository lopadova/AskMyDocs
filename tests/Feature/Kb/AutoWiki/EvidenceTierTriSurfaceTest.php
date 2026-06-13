<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\AutoWiki\EvidenceTierService;
use App\Support\Canonical\EvidenceTier;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.11/P1b (AutoSci #67) — the evidence-tier capability across the R44 surfaces:
 * the shared EvidenceTierService (PHP), the `kb:evidence-tier` Artisan command
 * (PHP), and the admin HTTP API. (The MCP tool is a thin delegate to the same
 * service — covered by the service test + KnowledgeBaseServerRegistrationTest;
 * laravel/mcp is a suggest-only dep so its tools aren't instantiable in CI.)
 */
final class EvidenceTierTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function doc(array $overrides = []): KnowledgeDocument
    {
        static $n = 0;
        $n++;

        return KnowledgeDocument::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'source_type' => 'markdown',
            'title' => "Doc {$n}",
            'source_path' => "docs/e-{$n}.md",
            'mime_type' => 'text/markdown',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => 'ver'.$n,
            'is_canonical' => false,
        ], $overrides));
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Adm', 'email' => 'adm-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('admin');

        return $u;
    }

    private function viewer(): User
    {
        $u = User::create(['name' => 'View', 'email' => 'v-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('viewer');

        return $u;
    }

    // ── PHP — service ──────────────────────────────────────────────────

    public function test_service_sets_tier_and_audits(): void
    {
        $doc = $this->doc(['evidence_tier' => null, 'slug' => 'svc-doc', 'doc_id' => 'svc-doc']);

        $updated = app(EvidenceTierService::class)->setTier($doc, EvidenceTier::PeerReviewed, 'tester');

        $this->assertSame('peer_reviewed', $updated->evidence_tier);
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'docs-v3',
            'event_type' => 'updated',
            'actor' => 'tester',
        ]);
    }

    public function test_find_by_doc_id_is_project_scoped_to_avoid_cross_project_collision(): void
    {
        // Same doc_id, same tenant, two different projects — legitimate (R10).
        $a = $this->doc(['doc_id' => 'shared-id', 'project_key' => 'proj-a']);
        $b = $this->doc(['doc_id' => 'shared-id', 'project_key' => 'proj-b']);

        $service = app(EvidenceTierService::class);

        // Bare doc_id is ambiguous → the caller must see BOTH (never silently pick one).
        $ambiguous = $service->findByDocId('shared-id');
        $this->assertCount(2, $ambiguous);
        $this->assertEqualsCanonicalizing(['proj-a', 'proj-b'], $ambiguous->pluck('project_key')->all());

        // project_key disambiguates to exactly one.
        $scoped = $service->findByDocId('shared-id', 'proj-b');
        $this->assertCount(1, $scoped);
        $this->assertSame($b->id, $scoped->first()->id);
        $this->assertNotSame($a->id, $scoped->first()->id);
    }

    public function test_service_taxonomy_shape(): void
    {
        $tax = app(EvidenceTierService::class)->taxonomy();
        $this->assertCount(8, $tax);
        $this->assertSame('guideline', $tax[0]['value']);
        $this->assertArrayHasKey('rank', $tax[0]);
        $this->assertArrayHasKey('low_confidence', $tax[0]);
    }

    // ── PHP — Artisan command ──────────────────────────────────────────

    public function test_command_shows_and_sets_tier(): void
    {
        $doc = $this->doc(['evidence_tier' => null]);

        $this->artisan('kb:evidence-tier', ['document' => $doc->id])
            ->expectsOutputToContain('(not assessed)')
            ->assertSuccessful();

        $this->artisan('kb:evidence-tier', ['document' => $doc->id, '--set' => 'official'])
            ->assertSuccessful();

        $this->assertSame('official', $doc->fresh()->evidence_tier);
    }

    public function test_command_rejects_invalid_tier_and_missing_doc(): void
    {
        $doc = $this->doc();
        $this->artisan('kb:evidence-tier', ['document' => $doc->id, '--set' => 'nonsense'])->assertFailed();
        $this->artisan('kb:evidence-tier', ['document' => 999999])->assertFailed();
    }

    // ── HTTP — admin API ───────────────────────────────────────────────

    public function test_api_taxonomy_returns_the_eight_tiers(): void
    {
        $this->actingAs($this->admin())->getJson('/api/admin/kb/evidence-tiers')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.value', 'guideline');
    }

    public function test_api_update_sets_tier(): void
    {
        $doc = $this->doc(['slug' => 'api-doc', 'doc_id' => 'api-doc']);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/kb/documents/{$doc->id}/evidence-tier", ['evidence_tier' => 'guideline'])
            ->assertOk()
            ->assertJsonPath('data.evidence_tier', 'guideline');

        $this->assertSame('guideline', $doc->fresh()->evidence_tier);
    }

    public function test_api_update_rejects_invalid_tier_with_422(): void
    {
        $doc = $this->doc();
        $this->actingAs($this->admin())
            ->patchJson("/api/admin/kb/documents/{$doc->id}/evidence-tier", ['evidence_tier' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('evidence_tier');
    }

    public function test_api_update_404_for_missing_doc(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/admin/kb/documents/999999/evidence-tier', ['evidence_tier' => 'official'])
            ->assertNotFound();
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->getJson('/api/admin/kb/evidence-tiers')
            ->assertForbidden();
    }
}
