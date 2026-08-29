<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Admin\ProvenanceInsightsService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Tests\TestCase;

/**
 * ADR 0028 phase 1 — the read-out that makes the label worth storing.
 *
 * Phase 1 enforces nothing, so the only thing that can be wrong here is the
 * counting, and the counting is what an operator will act on: a figure that
 * silently included another tenant's documents, or that reported "100%
 * internal" for a corpus nobody has assessed, is worse than no figure.
 */
final class ProvenanceInsightsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
    }

    private function makeDocument(string $path, ?string $tier, string $project = 'default', ?string $tenantId = null): void
    {
        KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId ?? $this->tenantId,
            'project_key' => $project,
            'source_type' => 'upload',
            'title' => basename($path),
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'indexed',
            'provenance_tier' => $tier,
            'document_hash' => hash('sha256', $path),
            'version_hash' => hash('sha256', $path.$tier),
        ]);
    }

    private function service(): ProvenanceInsightsService
    {
        return app(ProvenanceInsightsService::class);
    }

    public function test_an_empty_corpus_reports_zeroes_rather_than_failing(): void
    {
        // R43/R14 — a fresh tenant has nothing to report, and that is an
        // answer, not an error.
        $summary = $this->service()->summary();

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0.0, $summary['externally_authored_pct']);
        $this->assertCount(3, $summary['tiers']);
    }

    public function test_it_counts_each_tier_and_the_external_share(): void
    {
        $this->makeDocument('wiki/a.md', ProvenanceTier::TrustedInternal->value);
        $this->makeDocument('wiki/b.md', ProvenanceTier::TrustedInternal->value);
        $this->makeDocument('mail/c.md', ProvenanceTier::UntrustedExternal->value);
        $this->makeDocument('gen/d.md', ProvenanceTier::MachineGenerated->value);

        $summary = $this->service()->summary();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(4, $summary['declared']);
        $this->assertSame(0, $summary['undeclared']);
        $this->assertSame(1, $summary['externally_authored']);
        $this->assertSame(25.0, $summary['externally_authored_pct']);

        $byTier = collect($summary['tiers'])->keyBy('tier');
        $this->assertSame(2, $byTier[ProvenanceTier::TrustedInternal->value]['count']);
        $this->assertSame(1, $byTier[ProvenanceTier::UntrustedExternal->value]['count']);
        $this->assertSame(1, $byTier[ProvenanceTier::MachineGenerated->value]['count']);
    }

    public function test_undeclared_documents_resolve_to_the_default_but_are_reported_separately(): void
    {
        // The distinction that stops a reassuring number from being mistaken
        // for evidence: null resolves to trusted-internal so the totals match
        // what retrieval sees, but `declared` says how much of that was
        // actually asserted by a connector rather than assumed.
        $this->makeDocument('legacy/a.md', null);
        $this->makeDocument('legacy/b.md', null);
        $this->makeDocument('mail/c.md', ProvenanceTier::UntrustedExternal->value);

        $summary = $this->service()->summary();

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['declared']);
        $this->assertSame(2, $summary['undeclared']);

        $byTier = collect($summary['tiers'])->keyBy('tier');
        $this->assertSame(2, $byTier[ProvenanceTier::TrustedInternal->value]['count']);
    }

    public function test_it_never_counts_another_tenants_documents(): void
    {
        // R30. A corpus figure that leaks across the tenant boundary is not a
        // slightly-wrong number, it is a disclosure.
        $this->makeDocument('mine/a.md', ProvenanceTier::TrustedInternal->value);
        $this->makeDocument('theirs/a.md', ProvenanceTier::UntrustedExternal->value, 'default', 'other-tenant');

        $summary = $this->service()->summary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame(0, $summary['externally_authored']);
    }

    public function test_soft_deleted_documents_are_excluded(): void
    {
        // R2 — an operator who deleted a document does not expect it to keep
        // counting against their corpus.
        $this->makeDocument('mail/a.md', ProvenanceTier::UntrustedExternal->value);
        KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'mail/a.md')->delete();

        $this->makeDocument('wiki/b.md', ProvenanceTier::TrustedInternal->value);

        $summary = $this->service()->summary();

        $this->assertSame(1, $summary['total']);
        $this->assertSame(0, $summary['externally_authored']);
    }

    public function test_it_can_scope_to_one_project(): void
    {
        $this->makeDocument('mail/a.md', ProvenanceTier::UntrustedExternal->value, 'support');
        $this->makeDocument('wiki/b.md', ProvenanceTier::TrustedInternal->value, 'engineering');

        $this->assertSame(1, $this->service()->summary('support')['externally_authored']);
        $this->assertSame(0, $this->service()->summary('engineering')['externally_authored']);
    }

    public function test_the_per_project_breakdown_surfaces_the_shape_an_average_hides(): void
    {
        // One project fed by a mailbox and one by a wiki average to something
        // reassuring; the point of the breakdown is that the first is 100%.
        $this->makeDocument('mail/a.md', ProvenanceTier::UntrustedExternal->value, 'support');
        $this->makeDocument('mail/b.md', ProvenanceTier::UntrustedExternal->value, 'support');
        $this->makeDocument('wiki/c.md', ProvenanceTier::TrustedInternal->value, 'engineering');

        $rows = collect($this->service()->byProject())->keyBy('project_key');

        $this->assertSame(100.0, $rows['support']['externally_authored_pct']);
        $this->assertSame(0.0, $rows['engineering']['externally_authored_pct']);
        // Ordered by external count, so the project that needs attention is
        // first rather than alphabetical.
        $this->assertSame('support', array_key_first($rows->all()));
    }

    public function test_an_unrecognised_tier_counts_the_same_way_in_both_views(): void
    {
        // A value written by a newer version, read here by an older one.
        // summary() resolves it through fromStorage(), which fails closed to
        // untrusted. If byProject() matched only the literal
        // 'untrusted-external' it would call the same row internal, and the
        // breakdown would contradict the headline directly above it — on
        // exactly the rows an operator is looking for.
        $this->makeDocument('mail/future.md', 'tier-from-a-newer-release', 'support');
        $this->makeDocument('wiki/a.md', ProvenanceTier::TrustedInternal->value, 'support');

        $summary = $this->service()->summary('support');
        $rows = collect($this->service()->byProject())->keyBy('project_key');

        $this->assertSame(1, $summary['externally_authored']);
        $this->assertSame(
            $summary['externally_authored'],
            $rows['support']['externally_authored'],
            'The per-project breakdown must agree with the headline it sits under.',
        );
    }

    public function test_the_per_project_breakdown_is_bounded_on_every_surface(): void
    {
        // The HTTP and MCP surfaces cap at 200; the command has to as well,
        // or the one surface that forgot becomes the way to pull the whole
        // project table.
        foreach (range(1, 5) as $i) {
            $this->makeDocument("p{$i}/a.md", ProvenanceTier::TrustedInternal->value, "project-{$i}");
        }

        $this->assertLessThanOrEqual(200, count($this->service()->byProject(100000)));
        $this->assertCount(1, $this->service()->byProject(1));
    }

    public function test_the_http_surface_returns_the_same_core_answer(): void
    {
        $this->seed(RbacSeeder::class);

        $this->makeDocument('mail/a.md', ProvenanceTier::UntrustedExternal->value, 'support');
        $this->makeDocument('wiki/b.md', ProvenanceTier::TrustedInternal->value, 'engineering');

        $admin = User::create([
            'name' => 'Provenance Admin',
            'email' => 'prov-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/provenance?per_project=1');

        $response->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.externally_authored', 1)
            // JSON has no float/int distinction for a whole number: 50.0
            // is serialised as 50, so the assertion matches the wire value.
            ->assertJsonPath('summary.externally_authored_pct', 50)
            ->assertJsonCount(2, 'per_project');
    }

    public function test_the_artisan_surface_returns_the_same_core_answer(): void
    {
        $this->makeDocument('mail/a.md', ProvenanceTier::UntrustedExternal->value);
        $this->makeDocument('wiki/b.md', ProvenanceTier::TrustedInternal->value);

        $this->artisan('kb:provenance', ['--tenant' => $this->tenantId])
            ->expectsOutputToContain('Externally authored: 1 of 2 documents (50%).')
            ->assertExitCode(0);
    }

    public function test_the_artisan_surface_says_when_nothing_was_declared(): void
    {
        // A corpus reading 100% internal because nobody declared anything has
        // not been assessed, and the command has to say so rather than let
        // the number pass for evidence.
        $this->makeDocument('legacy/a.md', null);

        $this->artisan('kb:provenance', ['--tenant' => $this->tenantId])
            ->expectsOutputToContain('No document carries a connector declaration yet')
            ->assertExitCode(0);
    }
}
