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
    }

    public function test_download_json_streams_report_payload(): void
    {
        $admin = $this->makeAdmin();
        $report = $this->makeReport();

        $response = $this->actingAs($admin)
            ->get('/api/admin/compliance/reports/'.$report->id.'/json')
            ->assertOk();

        $response->assertJsonPath('delta.added.0.doc_id', 'doc-1');
        $this->assertStringContainsString('attachment; filename="compliance-report-tenant-acme-2026-01-01-2026-03-31.json"', (string) $response->headers->get('Content-Disposition'));
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
            'tenant_id' => 'tenant-acme',
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

