<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\ProjectMembership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Discovery read-only: elenca le "aziende" (project_key) presenti — da `projects`
 * E dai `knowledge_documents` — con #documenti, #chunk, membership (chi può
 * chattare) e se esiste un connettore IMAP. È la base concreta del passo
 * "controlla quali aziende ci sono" prima di estendere i seeder con le e-mail.
 *
 * Mostra anche i project_key ORFANI (documenti presenti ma nessuna riga Project
 * né membership): è esattamente lo stato "la chat non trova niente" che il
 * connettore può creare ingerendo in un project_key non registrato.
 *
 *   php artisan demo:list-companies
 *   php artisan demo:list-companies --tenant=default
 */
class DemoListCompaniesCommand extends Command
{
    protected $signature = 'demo:list-companies
        {--tenant= : Filtra per tenant_id}';

    protected $description = 'Elenca aziende/progetti con #documenti, #chunk, membership e stato connettore (read-only).';

    public function handle(): int
    {
        $tenantFilter = $this->option('tenant');
        $tenantFilter = is_string($tenantFilter) && $tenantFilter !== '' ? $tenantFilter : null;

        $keys = $this->collectProjectKeys($tenantFilter);

        if ($keys === []) {
            $this->warn('Nessuna azienda/progetto trovato'.($tenantFilter ? " per tenant '{$tenantFilter}'." : '.'));

            return self::SUCCESS;
        }

        $projectNames = $this->projectNames($tenantFilter);
        $connectorMap = $this->connectorMap($tenantFilter);

        $rows = [];
        foreach ($keys as $key) {
            [$tenantId, $projectKey] = explode('|', $key, 2);

            $memberships = ProjectMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('project_key', $projectKey)
                ->with('user')
                ->get();

            $memberEmails = $memberships
                ->map(static fn (ProjectMembership $m) => $m->user?->email)
                ->filter()
                ->implode(', ');

            $name = $projectNames[$key] ?? '— (orfano: nessuna riga projects)';

            $rows[] = [
                $tenantId,
                $projectKey,
                $name,
                $this->documentCount($tenantId, $projectKey),
                $this->chunkCount($tenantId, $projectKey),
                $memberships->count().($memberEmails !== '' ? " ({$memberEmails})" : ''),
                $connectorMap[$key] ?? '—',
            ];
        }

        $this->table(
            ['tenant', 'project_key', 'name', 'docs', 'chunks', 'members', 'connector'],
            $rows,
        );

        $this->line(sprintf('%d aziende/progetti.', count($rows)));

        return self::SUCCESS;
    }

    /**
     * Unione di (tenant_id, project_key) da `projects` e da `knowledge_documents`.
     *
     * @return list<string>  chiavi "tenant_id|project_key", ordinate
     */
    private function collectProjectKeys(?string $tenantFilter): array
    {
        $keys = [];

        $projects = Project::query()
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->get(['tenant_id', 'project_key']);
        foreach ($projects as $project) {
            $keys[$project->tenant_id.'|'.$project->project_key] = true;
        }

        $docKeys = KnowledgeDocument::query()
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->select('tenant_id', 'project_key')
            ->distinct()
            ->get();
        foreach ($docKeys as $row) {
            $keys[$row->tenant_id.'|'.$row->project_key] = true;
        }

        $sorted = array_keys($keys);
        sort($sorted);

        return $sorted;
    }

    /**
     * @return array<string,string>  "tenant_id|project_key" => name
     */
    private function projectNames(?string $tenantFilter): array
    {
        $map = [];
        $projects = Project::query()
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->get(['tenant_id', 'project_key', 'name']);
        foreach ($projects as $project) {
            $map[$project->tenant_id.'|'.$project->project_key] = (string) $project->name;
        }

        return $map;
    }

    /**
     * @return array<string,string>  "tenant_id|project_key" => descrizione connettori
     */
    private function connectorMap(?string $tenantFilter): array
    {
        if (! Schema::hasTable('connector_installations')) {
            return [];
        }

        $map = [];
        $installations = ConnectorInstallation::query()
            ->when($tenantFilter !== null, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->get();
        foreach ($installations as $installation) {
            // v8.20: project_key è una COLONNA (non più in config_json).
            $projectKey = (string) ($installation->project_key ?? '');
            if ($projectKey === '') {
                continue;
            }
            $key = $installation->tenant_id.'|'.$projectKey;
            $map[$key] = trim(($map[$key] ?? '').sprintf(
                ' %s#%d(%s)',
                $installation->connector_name,
                $installation->id,
                $installation->status,
            ));
        }

        return $map;
    }

    private function documentCount(string $tenantId, string $projectKey): int
    {
        return KnowledgeDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('project_key', $projectKey)
            ->count();
    }

    private function chunkCount(string $tenantId, string $projectKey): int
    {
        return KnowledgeChunk::query()
            ->where('tenant_id', $tenantId)
            ->where('project_key', $projectKey)
            ->count();
    }
}
