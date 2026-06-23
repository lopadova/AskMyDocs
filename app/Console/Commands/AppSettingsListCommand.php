<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.22 (Ciclo 3) — PHP read surface (R44) for runtime configuration governance.
 *
 * The Artisan sibling of the HTTP `admin/app-settings` index and the MCP
 * `AppSettingsTool`, over the SAME core {@see AppSettingsResolver}. Lists every
 * governable key with its effective value + provenance, tenant-scoped (R30).
 */
final class AppSettingsListCommand extends Command
{
    protected $signature = 'app-settings:list
                            {--tenant=default : Tenant to report on}
                            {--project=* : Project scope to resolve overrides for (defaults to the tenant-wide "*")}';

    protected $description = 'List governable runtime settings with their effective value + source for a tenant.';

    public function handle(AppSettingsResolver $resolver, TenantContext $tenants): int
    {
        $tenant = (string) $this->option('tenant');
        $project = (string) ($this->option('project') ?: AppSetting::WILDCARD);

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $settings = $resolver->all($tenant, $project);
        } finally {
            $tenants->set($previous);
        }

        $this->info("Runtime settings (tenant: {$tenant}, project: {$project})");
        $this->table(
            ['Key', 'Value', 'Type', 'Source', 'Deploy-only'],
            array_map(
                static fn (array $s) => [
                    $s['key'],
                    self::renderValue($s['value']),
                    $s['type'],
                    $s['source'],
                    $s['deploy_only'] ? 'yes' : 'no',
                ],
                $settings,
            ),
        );

        return self::SUCCESS;
    }

    private static function renderValue(mixed $value): string
    {
        if ($value === null) {
            return '(null)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
