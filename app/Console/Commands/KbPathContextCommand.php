<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KbPathContext;
use App\Services\Kb\PathContext\PathContextService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — manage path contexts from the shell. Thin
 * adapter over {@see PathContextService} (R44).
 *
 *   php artisan kb:path-context list   --project=hr
 *   php artisan kb:path-context add    --project=hr --prefix=policies --description="Policy HR vigenti"
 *   php artisan kb:path-context remove --project=hr --prefix=policies
 *
 * Operator surface: works regardless of `KB_PATH_CONTEXTS_ENABLED` (that flag
 * gates only the HTTP/MCP surfaces and the prompt/citation injection), so an
 * operator can stage contexts before switching the feature on.
 */
final class KbPathContextCommand extends Command
{
    protected $signature = 'kb:path-context
        {action : list | add | remove}
        {--project= : project_key (required for add/remove)}
        {--prefix= : source_path prefix, e.g. policies/ (required for add/remove)}
        {--description= : description, 1-500 chars (required for add)}
        {--tenant=default : Tenant that owns the contexts}';

    protected $description = 'List, add or remove path contexts (descriptions bound to a source_path prefix).';

    public function handle(PathContextService $pathContextService, TenantContext $tenantContext): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));
        $prefix = trim((string) $this->option('prefix'));

        if (! in_array($action, ['list', 'add', 'remove'], true)) {
            $this->error('Action must be one of: list, add, remove.');

            return self::FAILURE;
        }
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        if ($action !== 'list' && ($project === '' || $prefix === '')) {
            $this->error('--project and --prefix are required for add/remove.');

            return self::FAILURE;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            return match ($action) {
                'list' => $this->doList($pathContextService, $tenant, $project),
                'add' => $this->doAdd($pathContextService, $tenant, $project, $prefix),
                'remove' => $this->doRemove($pathContextService, $tenant, $project, $prefix),
            };
        } finally {
            $tenantContext->set($previous);
        }
    }

    private function doList(PathContextService $svc, string $tenant, string $project): int
    {
        $rows = $svc->list($tenant, $project === '' ? null : $project);
        if ($rows->isEmpty()) {
            $this->info('No path contexts.');

            return self::SUCCESS;
        }

        $this->table(['id', 'project', 'prefix', 'description'], $rows->map(fn (KbPathContext $c): array => [$c->id, $c->project_key, $c->path_prefix, $c->description])->all());

        return self::SUCCESS;
    }

    private function doAdd(PathContextService $svc, string $tenant, string $project, string $prefix): int
    {
        try {
            $row = $svc->upsert($tenant, $project, $prefix, (string) $this->option('description'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf("Saved path context #%d for '%s' / '%s'.", $row->id, $row->project_key, $row->path_prefix));

        return self::SUCCESS;
    }

    private function doRemove(PathContextService $svc, string $tenant, string $project, string $prefix): int
    {
        try {
            $normalised = $svc->normalizePrefix($prefix);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $row = KbPathContext::query()->forTenant($tenant)->where('project_key', $project)->where('path_prefix', $normalised)->first();
        if ($row === null || ! $svc->delete($tenant, $row->id)) {
            $this->error('No such path context.');

            return self::FAILURE;
        }

        $this->info('Removed.');

        return self::SUCCESS;
    }
}
