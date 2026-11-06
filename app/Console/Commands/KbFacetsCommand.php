<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Agent\KbFacetsService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.44/W4 (ADR 0035 §D6.1) — print the corpus facets. Thin adapter over
 * {@see KbFacetsService} (R44). Operator surface: runs without an
 * authenticated principal, so no per-user ACL narrows it, and it works
 * regardless of `KB_MCP_FACETS_ENABLED` (that flag gates HTTP + MCP only).
 */
final class KbFacetsCommand extends Command
{
    protected $signature = 'kb:facets
        {--project= : Restrict to one project_key}
        {--tenant=default : Tenant to report on}
        {--depth=2 : Folder prefix depth (1 or 2)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'List the filterable dimensions of the knowledge base with counts.';

    public function handle(KbFacetsService $kbFacetsService, TenantContext $tenantContext): int
    {
        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            $facets = $kbFacetsService->for($tenant, $project === '' ? null : $project, (int) $this->option('depth'));
        } finally {
            $tenantContext->set($previous);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($facets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d document(s)', $facets['document_count']));
        foreach (['source_types', 'canonical_types', 'canonical_statuses', 'languages'] as $dimension) {
            $this->line("\n<comment>{$dimension}</comment>");
            $this->table(['value', 'count'], array_map(fn (array $r): array => [$r['value'], $r['count']], $facets[$dimension]));
        }
        $this->line("\n<comment>tags</comment>");
        $this->table(['slug', 'label', 'count'], array_map(fn (array $r): array => [$r['slug'], $r['label'], $r['count']], $facets['tags']));
        $this->line("\n<comment>folders</comment>");
        $this->table(['prefix', 'count'], array_map(fn (array $r): array => [$r['prefix'], $r['count']], $facets['folders']));
        $this->line(sprintf("\nsource_updated_at: %s … %s", $facets['source_updated_at']['min'] ?? '—', $facets['source_updated_at']['max'] ?? '—'));

        return self::SUCCESS;
    }
}
