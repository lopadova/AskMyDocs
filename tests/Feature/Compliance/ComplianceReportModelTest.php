<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\ComplianceReport;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ComplianceReportModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_auto_fills_tenant_id_from_context(): void
    {
        app(TenantContext::class)->set('tenant-acme');

        $report = ComplianceReport::create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => ['delta' => [], 'audit' => []],
            'hash_sha256' => str_repeat('1', 64),
            'hash_hmac' => str_repeat('2', 64),
            'generated_at' => Carbon::parse('2026-04-01T06:00:00Z'),
            'generated_by' => $this->makeUser()->id,
        ]);

        $this->assertSame('tenant-acme', $report->fresh()->tenant_id);
    }

    public function test_unique_tenant_period_blocks_duplicate_window(): void
    {
        ComplianceReport::create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => ['delta' => [], 'audit' => []],
            'hash_sha256' => str_repeat('a', 64),
            'hash_hmac' => str_repeat('b', 64),
            'generated_at' => Carbon::parse('2026-04-01T06:00:00Z'),
            'generated_by' => $this->makeUser()->id,
        ]);

        $this->expectException(QueryException::class);
        ComplianceReport::create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => ['delta' => ['x'], 'audit' => []],
            'hash_sha256' => str_repeat('c', 64),
            'hash_hmac' => str_repeat('d', 64),
            'generated_at' => Carbon::parse('2026-04-02T06:00:00Z'),
            'generated_by' => $this->makeUser()->id,
        ]);
    }

    public function test_same_period_can_coexist_across_tenants(): void
    {
        app(TenantContext::class)->set('tenant-a');
        $a = ComplianceReport::create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => ['delta' => ['doc-a'], 'audit' => []],
            'hash_sha256' => str_repeat('a', 64),
            'hash_hmac' => str_repeat('b', 64),
            'generated_at' => Carbon::parse('2026-04-01T06:00:00Z'),
            'generated_by' => $this->makeUser()->id,
        ]);

        app(TenantContext::class)->set('tenant-b');
        $b = ComplianceReport::create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => ['delta' => ['doc-b'], 'audit' => []],
            'hash_sha256' => str_repeat('c', 64),
            'hash_hmac' => str_repeat('d', 64),
            'generated_at' => Carbon::parse('2026-04-01T06:05:00Z'),
            'generated_by' => $this->makeUser()->id,
        ]);

        $this->assertSame('tenant-a', $a->tenant_id);
        $this->assertSame('tenant-b', $b->tenant_id);
    }

    public function test_payload_and_date_casts_roundtrip(): void
    {
        $report = ComplianceReport::create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => [
                'delta' => ['added' => ['doc-1'], 'removed' => []],
                'audit' => ['kb_canonical_audit' => [['event_type' => 'promoted']]],
            ],
            'hash_sha256' => str_repeat('e', 64),
            'hash_hmac' => str_repeat('f', 64),
            'pdf_path' => 'compliance/default/q1-2026.pdf',
            'generated_at' => Carbon::parse('2026-04-01T06:00:00Z'),
            'generated_by' => $this->makeUser()->id,
        ]);

        $fresh = $report->fresh();
        $this->assertSame(['doc-1'], $fresh->payload_json['delta']['added']);
        $this->assertInstanceOf(Carbon::class, $fresh->period_start);
        $this->assertInstanceOf(Carbon::class, $fresh->period_end);
        $this->assertInstanceOf(Carbon::class, $fresh->generated_at);
        $this->assertSame('2026-01-01', $fresh->period_start->format('Y-m-d'));
        $this->assertSame('2026-03-31', $fresh->period_end->format('Y-m-d'));
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'compliance-test-user',
            'email' => 'compliance-'.uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}

