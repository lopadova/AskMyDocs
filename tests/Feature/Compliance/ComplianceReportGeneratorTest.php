<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\User;
use App\Services\Compliance\ComplianceReportGenerator;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ComplianceReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('askmydocs.compliance.hmac_secret', 'test-compliance-secret');
        app(TenantContext::class)->set('tenant-acme');
    }

    public function test_generate_builds_delta_audit_and_hashes(): void
    {
        $user = $this->makeUser();
        $this->seedKnowledgeDocuments('tenant-acme');
        $this->seedAudits('tenant-acme');

        $report = app(ComplianceReportGenerator::class)->generate(
            'tenant-acme',
            '2026-01-01',
            '2026-03-31',
            $user->id,
        );

        $this->assertSame('tenant-acme', $report->tenant_id);
        $this->assertSame('2026-01-01', $report->period_start->toDateString());
        $this->assertSame('2026-03-31', $report->period_end->toDateString());
        $this->assertSame(2, count($report->payload_json['delta']['added']));
        $this->assertSame(1, count($report->payload_json['delta']['removed']));
        $this->assertSame(1, count($report->payload_json['delta']['superseded']));
        $this->assertNotEmpty($report->payload_json['delta']['canonical_diff_snippets']);
        $this->assertSame(3, count($report->payload_json['audit']['kb_canonical_audit']));
        $this->assertSame(2, count($report->payload_json['audit']['admin_command_audits']));
        $this->assertArrayHasKey('updated', $report->payload_json['audit']['event_type_counts']);
        $this->assertArrayHasKey('admin_command:kb:rebuild-graph', $report->payload_json['audit']['event_type_counts']);
        $this->assertNotEmpty($report->hash_sha256);
        $this->assertNotEmpty($report->hash_hmac);

        $payloadJson = json_encode($report->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expectedHmac = hash_hmac('sha256', (string) $payloadJson.'tenant-acme2026-01-012026-03-31', 'test-compliance-secret');
        $this->assertSame($expectedHmac, $report->hash_hmac);
    }

    public function test_tamper_changes_recomputed_hash(): void
    {
        $user = $this->makeUser();
        $this->seedKnowledgeDocuments('tenant-acme');
        $this->seedAudits('tenant-acme');

        $report = app(ComplianceReportGenerator::class)->generate('tenant-acme', '2026-01-01', '2026-03-31', $user->id);
        $originalPayload = json_encode($report->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $originalHash = hash('sha256', (string) $originalPayload);

        $tamperedPayload = (string) $originalPayload;
        $tamperedPayload = str_replace('"doc-1"', '"doc-1-tampered"', $tamperedPayload);
        $tamperedHash = hash('sha256', $tamperedPayload);

        $this->assertSame($originalHash, $report->hash_sha256);
        $this->assertNotSame($originalHash, $tamperedHash);

        $originalHmac = hash_hmac('sha256', (string) $originalPayload.'tenant-acme2026-01-012026-03-31', 'test-compliance-secret');
        $tamperedHmac = hash_hmac('sha256', $tamperedPayload.'tenant-acme2026-01-012026-03-31', 'test-compliance-secret');
        $this->assertSame($originalHmac, $report->hash_hmac);
        $this->assertNotSame($originalHmac, $tamperedHmac);
    }

    private function seedKnowledgeDocuments(string $tenantId): void
    {
        DB::table('knowledge_documents')->insert([
            [
                'tenant_id' => $tenantId,
                'project_key' => 'proj-a',
                'source_type' => 'markdown',
                'title' => 'Doc A',
                'source_path' => '/docs/a.md',
                'status' => 'indexed',
                'doc_id' => 'doc-1',
                'slug' => 'doc-1',
                'canonical_type' => 'decision',
                'canonical_status' => 'accepted',
                'is_canonical' => 1,
                'retrieval_priority' => 50,
                'source_of_truth' => 1,
                'created_at' => '2026-01-10 10:00:00',
                'updated_at' => '2026-01-10 10:00:00',
                'deleted_at' => null,
            ],
            [
                'tenant_id' => $tenantId,
                'project_key' => 'proj-a',
                'source_type' => 'markdown',
                'title' => 'Doc B',
                'source_path' => '/docs/b.md',
                'status' => 'indexed',
                'doc_id' => 'doc-2',
                'slug' => 'doc-2',
                'canonical_type' => 'decision',
                'canonical_status' => 'superseded',
                'is_canonical' => 1,
                'retrieval_priority' => 50,
                'source_of_truth' => 1,
                'created_at' => '2026-02-10 10:00:00',
                'updated_at' => '2026-02-15 10:00:00',
                'deleted_at' => null,
            ],
            [
                'tenant_id' => $tenantId,
                'project_key' => 'proj-a',
                'source_type' => 'markdown',
                'title' => 'Doc C',
                'source_path' => '/docs/c.md',
                'status' => 'indexed',
                'doc_id' => 'doc-3',
                'slug' => 'doc-3',
                'canonical_type' => 'guide',
                'canonical_status' => 'accepted',
                'is_canonical' => 1,
                'retrieval_priority' => 50,
                'source_of_truth' => 1,
                'created_at' => '2025-12-10 10:00:00',
                'updated_at' => '2026-01-20 10:00:00',
                'deleted_at' => '2026-01-21 10:00:00',
            ],
        ]);
    }

    private function seedAudits(string $tenantId): void
    {
        DB::table('kb_canonical_audit')->insert([
            [
                'tenant_id' => $tenantId,
                'project_key' => 'proj-a',
                'doc_id' => 'doc-1',
                'slug' => 'doc-1',
                'event_type' => 'promoted',
                'actor' => 'alice',
                'before_json' => json_encode(['markdown' => "before line\nx"]),
                'after_json' => json_encode(['markdown' => "after line\ny"]),
                'metadata_json' => json_encode(['source' => 'test']),
                'created_at' => '2026-01-12 11:00:00',
            ],
            [
                'tenant_id' => $tenantId,
                'project_key' => 'proj-a',
                'doc_id' => 'doc-2',
                'slug' => 'doc-2',
                'event_type' => 'updated',
                'actor' => 'bob',
                'before_json' => json_encode(['markdown' => 'old body']),
                'after_json' => json_encode(['markdown' => 'new body']),
                'metadata_json' => json_encode(['source' => 'test']),
                'created_at' => '2026-02-20 11:00:00',
            ],
            [
                'tenant_id' => $tenantId,
                'project_key' => 'proj-a',
                'doc_id' => 'doc-2',
                'slug' => 'doc-2',
                'event_type' => 'superseded',
                'actor' => 'alice',
                'before_json' => json_encode(['markdown' => 'v1']),
                'after_json' => json_encode(['markdown' => 'v2']),
                'metadata_json' => json_encode(['source' => 'test']),
                'created_at' => '2026-03-01 11:00:00',
            ],
        ]);

        DB::table('admin_command_audit')->insert([
            [
                'tenant_id' => $tenantId,
                'user_id' => null,
                'command' => 'kb:rebuild-graph',
                'args_json' => json_encode(['project' => 'proj-a']),
                'status' => 'completed',
                'exit_code' => 0,
                'stdout_head' => 'ok',
                'error_message' => null,
                'started_at' => '2026-02-01 06:00:00',
                'completed_at' => '2026-02-01 06:00:05',
                'client_ip' => null,
                'user_agent' => null,
            ],
            [
                'tenant_id' => $tenantId,
                'user_id' => null,
                'command' => 'kb:health-recompute',
                'args_json' => json_encode(['tenant' => $tenantId]),
                'status' => 'completed',
                'exit_code' => 0,
                'stdout_head' => 'ok',
                'error_message' => null,
                'started_at' => '2026-03-01 06:00:00',
                'completed_at' => '2026-03-01 06:00:10',
                'client_ip' => null,
                'user_agent' => null,
            ],
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'compliance-generator-user',
            'email' => 'compliance-generator-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
