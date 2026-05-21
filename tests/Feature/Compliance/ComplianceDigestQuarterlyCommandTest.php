<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ComplianceDigestQuarterlyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('askmydocs.compliance.hmac_secret', 'test-compliance-secret');

        if (! Schema::hasTable('tenant_scheduler_overrides')) {
            Schema::create('tenant_scheduler_overrides', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 50)->default('default')->index();
                $table->string('slot_name', 64);
                $table->string('cron', 64);
                $table->boolean('enabled')->default(true);
                $table->string('timezone', 64)->default('UTC');
                $table->timestamps();
                $table->unique(['tenant_id', 'slot_name'], 'uq_tenant_scheduler_overrides_tenant_slot');
            });
        }
    }

    public function test_it_generates_for_opted_in_tenants_for_previous_quarter_and_skips_existing(): void
    {
        DB::table('tenant_scheduler_overrides')->insert([
            [
                'tenant_id' => 'tenant-alpha',
                'slot_name' => 'compliance_digest_quarterly',
                'cron' => '0 6 1 1,4,7,10 *',
                'enabled' => true,
                'timezone' => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 'tenant-beta',
                'slot_name' => 'compliance_digest_quarterly',
                'cron' => '0 6 1 1,4,7,10 *',
                'enabled' => true,
                'timezone' => 'UTC',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('compliance_reports')->insert([
            'tenant_id' => 'tenant-beta',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'payload_json' => json_encode(['delta' => [], 'audit' => [], 'period' => ['start' => '2026-01-01', 'end' => '2026-03-31']]),
            'hash_sha256' => str_repeat('a', 64),
            'hash_hmac' => str_repeat('b', 64),
            'generated_at' => now(),
            'generated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('compliance:digest-quarterly', [
            '--at' => '2026-04-15T12:00:00Z',
        ])
            ->expectsOutput('[tenant-alpha] generated compliance report for 2026-01-01..2026-03-31.')
            ->expectsOutput('[tenant-beta] already has report for 2026-01-01..2026-03-31; skipped.')
            ->expectsOutput('Done. created=1, skipped=1, period=2026-01-01..2026-03-31.')
            ->assertSuccessful();

        $alphaReport = DB::table('compliance_reports')
            ->where('tenant_id', 'tenant-alpha')
            ->whereDate('period_start', '2026-01-01')
            ->whereDate('period_end', '2026-03-31')
            ->first();
        $this->assertNotNull($alphaReport, 'tenant-alpha quarterly report should be generated for 2026-Q1.');
    }

    public function test_it_exits_when_no_tenant_is_opted_in(): void
    {
        $this->artisan('compliance:digest-quarterly', [
            '--at' => '2026-04-15T12:00:00Z',
        ])
            ->expectsOutput('No tenant opted-in for compliance quarterly digest; nothing to do.')
            ->assertSuccessful();

        $this->assertDatabaseCount('compliance_reports', 0);
    }
}
