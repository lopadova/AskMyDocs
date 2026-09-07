<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Admin\TenantResetService;
use App\Support\SystemTenantRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Destructive operator-only reset for one tenant.
 *
 * The command intentionally is not exposed through the browser maintenance
 * allowlist. Shell access is the authorization boundary, and an interactive
 * confirmation remains mandatory unless --force is supplied explicitly.
 */
final class ResetTenantCommand extends Command
{
    protected $signature = 'tenant:reset
        {tenant : Exact tenant slug to delete and recreate empty}
        {--force : Skip the interactive confirmation}
        {--keep-files : Preserve source files on the configured KB disks}';

    protected $description = 'Permanently delete one tenant\'s data and recreate only its empty registry row.';

    public function handle(TenantResetService $resetter): int
    {
        $slug = trim((string) $this->argument('tenant'));

        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $slug)) {
            $this->error('Invalid tenant slug. Pass the exact lowercase slug (a-z, 0-9, _ or -, max 50 characters).');

            return self::FAILURE;
        }

        if (! Schema::hasTable('tenants')) {
            $this->error("The 'tenants' registry table is missing. Run the migrations before resetting a tenant.");

            return self::FAILURE;
        }

        if (SystemTenantRegistry::isReserved($slug)) {
            $this->error("Refusing to reset reserved tenant '{$slug}'.");

            return self::FAILURE;
        }

        try {
            $preview = $resetter->inspect($slug);
        } catch (ModelNotFoundException) {
            $this->error("Tenant '{$slug}' does not exist.");

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Unable to inspect the tenant safely: '.$e->getMessage());

            return self::FAILURE;
        }

        $tenant = $preview['tenant'];
        if (Schema::hasColumn('tenants', 'is_system') && (bool) $tenant->getAttribute('is_system')) {
            $this->error("Refusing to reset system tenant '{$slug}'.");

            return self::FAILURE;
        }

        $this->warn("Tenant '{$slug}' ({$tenant->name}) will be permanently emptied.");
        $this->line('Global user accounts are preserved; their tenant memberships are removed.');
        $this->line($this->option('keep-files')
            ? 'KB source files will be preserved (--keep-files).'
            : 'KB source files referenced by this tenant will be deleted when no other document uses them.');
        $this->line('Pause queue workers first: already-running jobs are not cancelled by this command.');

        if ($preview['table_counts'] !== []) {
            $this->table(
                ['Table', 'Rows'],
                collect($preview['table_counts'])
                    ->map(static fn (int $count, string $table): array => [$table, $count])
                    ->values()
                    ->all(),
            );
        }

        $this->line("Total tenant-owned rows found: {$preview['total_rows']}");

        if (! $this->option('force') && ! $this->confirm("Delete and recreate tenant '{$slug}' now?", false)) {
            $this->info('Tenant reset cancelled; no data was changed.');

            return self::SUCCESS;
        }

        try {
            $result = $resetter->reset($slug, deleteFiles: ! (bool) $this->option('keep-files'));
        } catch (ModelNotFoundException) {
            $this->error("Tenant '{$slug}' disappeared before the reset could start.");

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Tenant reset failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::notice('Tenant reset and recreated empty from CLI.', [
            'tenant_id' => $slug,
            'new_registry_id' => $result['tenant']->id,
            'rows_deleted' => $result['total_deleted'],
            'documents_deleted' => $result['documents_deleted'],
            'files_deleted' => $result['files_deleted'],
            'files_preserved' => (bool) $this->option('keep-files'),
        ]);

        $this->info("Tenant '{$slug}' was recreated empty.");
        $this->table(['Result', 'Value'], [
            ['New registry id', (string) $result['tenant']->id],
            ['Rows deleted', (string) $result['total_deleted']],
            ['Documents deleted', (string) $result['documents_deleted']],
            ['KB files deleted', (string) $result['files_deleted']],
            ['Projects recreated', '0'],
            ['Memberships recreated', '0'],
        ]);

        return self::SUCCESS;
    }
}
