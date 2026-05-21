<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ComplianceReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ComplianceReport>
 */
final class ComplianceReportFactory extends Factory
{
    protected $model = ComplianceReport::class;

    public function definition(): array
    {
        $periodStart = Carbon::create(2026, 1, 1)->startOfDay();
        $periodEnd = Carbon::create(2026, 3, 31)->startOfDay();

        return [
            'tenant_id' => 'default',
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'payload_json' => [
                'delta' => ['added' => [], 'removed' => [], 'superseded' => [], 'promoted' => []],
                'audit' => ['kb_canonical_audit' => [], 'admin_command_audits' => []],
            ],
            'hash_sha256' => str_repeat('a', 64),
            'hash_hmac' => str_repeat('b', 64),
            'pdf_path' => null,
            'generated_at' => Carbon::now(),
            'generated_by' => User::query()->value('id'),
        ];
    }
}

