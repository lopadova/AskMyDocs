<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ComplianceReport;
use App\Services\Compliance\ComplianceReportGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ComplianceDigestQuarterlyCommand extends Command
{
    protected $signature = 'compliance:digest-quarterly
                            {--tenant= : Restrict to a single tenant_id}
                            {--at= : Reference datetime (ISO-8601) used to compute the prior quarter window}';

    protected $description = 'Generate quarterly compliance reports for tenants opted-in via scheduler overrides (or legacy tenant_settings).';

    public function handle(ComplianceReportGenerator $generator): int
    {
        $at = $this->resolveReferenceTime();
        [$periodStart, $periodEnd] = $this->previousQuarterWindow($at);
        $tenantIds = $this->resolveTenantIds();

        if ($tenantIds === []) {
            $this->info('No tenant opted-in for compliance quarterly digest; nothing to do.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        foreach ($tenantIds as $tenantId) {
            $exists = ComplianceReport::query()
                ->where('tenant_id', $tenantId)
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->exists();

            if ($exists) {
                $this->line("[{$tenantId}] already has report for {$periodStart}..{$periodEnd}; skipped.");
                $skipped++;
                continue;
            }

            $generator->generate($tenantId, $periodStart, $periodEnd, null);
            $this->info("[{$tenantId}] generated compliance report for {$periodStart}..{$periodEnd}.");
            $created++;
        }

        $this->info("Done. created={$created}, skipped={$skipped}, period={$periodStart}..{$periodEnd}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function previousQuarterWindow(CarbonImmutable $at): array
    {
        $quarterStart = $at->startOfQuarter();
        $start = $quarterStart->subQuarter()->startOfQuarter()->toDateString();
        $end = $quarterStart->subDay()->toDateString();

        return [$start, $end];
    }

    private function resolveReferenceTime(): CarbonImmutable
    {
        $explicit = trim((string) $this->option('at'));
        if ($explicit === '') {
            return CarbonImmutable::now('UTC');
        }

        return CarbonImmutable::parse($explicit, 'UTC');
    }

    /**
     * @return list<string>
     */
    private function resolveTenantIds(): array
    {
        $explicitTenant = trim((string) $this->option('tenant'));
        $slotName = 'compliance_digest_quarterly';

        if ($explicitTenant !== '') {
            $enabled = false;
            if (Schema::hasTable('tenant_scheduler_overrides')) {
                $enabled = DB::table('tenant_scheduler_overrides')
                    ->where('tenant_id', $explicitTenant)
                    ->where('slot_name', $slotName)
                    ->where('enabled', true)
                    ->exists();
            }

            if (! $enabled && Schema::hasTable('tenant_settings') && Schema::hasColumn('tenant_settings', 'compliance_quarterly_auto')) {
                $enabled = DB::table('tenant_settings')
                    ->where('tenant_id', $explicitTenant)
                    ->where('compliance_quarterly_auto', true)
                    ->exists();
            }

            return $enabled ? [$explicitTenant] : [];
        }

        if (Schema::hasTable('tenant_scheduler_overrides')) {
            return DB::table('tenant_scheduler_overrides')
                ->where('slot_name', $slotName)
                ->where('enabled', true)
                ->orderBy('tenant_id')
                ->pluck('tenant_id')
                ->filter(static fn ($v): bool => is_string($v) && $v !== '')
                ->values()
                ->all();
        }

        if (Schema::hasTable('tenant_settings') && Schema::hasColumn('tenant_settings', 'compliance_quarterly_auto')) {
            return DB::table('tenant_settings')
                ->where('compliance_quarterly_auto', true)
                ->orderBy('tenant_id')
                ->pluck('tenant_id')
                ->filter(static fn ($v): bool => is_string($v) && $v !== '')
                ->values()
                ->all();
        }

        return [];
    }
}
