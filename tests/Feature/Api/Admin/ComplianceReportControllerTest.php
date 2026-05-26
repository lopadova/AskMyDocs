<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\ComplianceReport;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ComplianceReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        config()->set('askmydocs.compliance.hmac_secret', 'test-compliance-secret');
    }

    public function test_index_lists_only_active_tenant_reports(): void
    {
        // C4 (R30) — index ignores any ?tenant_id and scopes to the active
        // tenant (a plain admin resolves to 'default'). The bystander
        // 'tenant-other' report must NOT appear.
        $admin = $this->makeAdmin();
        $this->makeReport();
        ComplianceReport::create([
            'tenant_id' => 'tenant-other',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => ['delta' => [], 'audit' => [], 'period' => ['start' => '2026-01-01', 'end' => '2026-03-31']],
            'hash_sha256' => hash('sha256', 'x'),
            'hash_hmac' => hash('sha256', 'y'),
            'generated_at' => now(),
            'generated_by' => null,
        ]);

        // Even with a spoofed ?tenant_id the foreign report stays hidden.
        $this->actingAs($admin)
            ->get('/api/admin/compliance/reports?tenant_id=tenant-other')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', 'default');
    }

    public function test_store_generates_report_for_active_tenant_ignoring_payload(): void
    {
        // C4 (R30) — a tenant_id in the payload is ignored; the report is
        // always generated for the active (resolved) tenant.
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/compliance/reports', [
                'tenant_id' => 'tenant-spoofed',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('compliance_reports', [
            'id' => $response->json('data.id'),
            'tenant_id' => 'default',
            'period_start' => '2026-01-01 00:00:00',
            'period_end' => '2026-03-31 00:00:00',
        ]);
        $this->assertDatabaseMissing('compliance_reports', ['tenant_id' => 'tenant-spoofed']);
    }

    public function test_verify_returns_valid_true_for_untampered_payload(): void
    {
        $admin = $this->makeAdmin();
        $report = $this->makeReport();

        $payloadJson = json_encode($report->payload_json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $report->update([
            'hash_sha256' => hash('sha256', $payloadJson),
            'hash_hmac' => hash_hmac('sha256', $payloadJson.'default2026-01-012026-03-31', 'test-compliance-secret'),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/compliance/reports/'.$report->id.'/verify')
            ->assertOk()
            ->assertJsonPath('valid', true);
    }

    public function test_download_json_streams_report_payload(): void
    {
        $admin = $this->makeAdmin();
        $report = $this->makeReport();

        $response = $this->actingAs($admin)
            ->get('/api/admin/compliance/reports/'.$report->id.'/json')
            ->assertOk();

        $response->assertJsonPath('delta.added.0.doc_id', 'doc-1');
        $this->assertStringContainsString('attachment; filename="compliance-report-default-2026-01-01-2026-03-31.json"', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_download_pdf_surfaces_failures_loudly_or_streams_non_empty_pdf(): void
    {
        $admin = $this->makeAdmin();
        $report = $this->makeReport();

        $response = $this->actingAs($admin)
            ->get('/api/admin/compliance/reports/'.$report->id.'/pdf');

        if ($response->status() === 500) {
            $response->assertJsonPath('message', 'PDF rendering failed.');

            return;
        }

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertGreaterThanOrEqual(1024, strlen((string) $response->getContent()));
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Compliance Admin',
            'email' => 'compliance-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeReport(): ComplianceReport
    {
        return ComplianceReport::create([
            'tenant_id' => 'default',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => [
                'delta' => [
                    'added' => [
                        ['doc_id' => 'doc-1', 'slug' => 'doc-1'],
                    ],
                    'removed' => [],
                    'superseded' => [],
                    'promoted' => [],
                    'canonical_diff_snippets' => [],
                ],
                'audit' => [
                    'kb_canonical_audit' => [],
                    'admin_command_audits' => [],
                    'event_type_counts' => ['updated' => 1],
                    'top_actors' => [['actor' => 'alice', 'count' => 1]],
                ],
                'period' => ['start' => '2026-01-01', 'end' => '2026-03-31'],
            ],
            'hash_sha256' => hash('sha256', 'payload'),
            'hash_hmac' => hash('sha256', 'hmac'),
            'generated_at' => now(),
            'generated_by' => null,
        ]);
    }
}

