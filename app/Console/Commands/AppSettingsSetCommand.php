<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * v8.22 (Ciclo 3) — PHP write surface (R44) for runtime configuration governance.
 *
 * The Artisan sibling of the HTTP `admin/app-settings` PUT, over the SAME core
 * {@see AppSettingsResolver}. Sets (or, with --clear, removes) a per-(tenant,
 * project) override, tenant-scoped (R30). Deploy-only / unknown keys + invalid
 * values are rejected via the resolver's ValidationException (surfaced loudly,
 * R14) — never silently ignored.
 */
final class AppSettingsSetCommand extends Command
{
    protected $signature = 'app-settings:set
                            {key : The governable setting key (see app-settings:list)}
                            {value? : The value to set (omit with --clear to remove the override)}
                            {--tenant=default : Tenant to write the override for}
                            {--project= : Project scope (defaults to the tenant-wide "*")}
                            {--clear : Remove the override at this scope instead of setting it}';

    protected $description = 'Set or clear a governable runtime setting override for a (tenant, project).';

    public function handle(AppSettingsResolver $resolver, TenantContext $tenants): int
    {
        $key = (string) $this->argument('key');
        $clear = (bool) $this->option('clear');
        $value = $clear ? null : $this->argument('value');

        if (! $clear && $value === null) {
            $this->error('Provide a value, or pass --clear to remove the override.');

            return self::FAILURE;
        }

        $tenant = (string) $this->option('tenant');
        $project = AppSetting::normalizeProjectKey($this->option('project'));

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $resolver->set($key, $value, $tenant, $project);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        } finally {
            $tenants->set($previous);
        }

        $action = $clear ? 'cleared' : 'set';
        $this->info("'{$key}' {$action} for tenant '{$tenant}', project '{$project}'.");

        return self::SUCCESS;
    }
}
