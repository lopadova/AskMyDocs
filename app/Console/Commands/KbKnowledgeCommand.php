<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSpecification;
use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Services\Kb\Knowledge\KnowledgeContract;
use App\Services\Kb\Knowledge\KnowledgeEnrichment;
use App\Services\Kb\Knowledge\KnowledgeReadService;
use App\Services\Kb\Knowledge\KnowledgeReprocessing;
use App\Services\Kb\Knowledge\KnowledgeSpecificationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

final class KbKnowledgeCommand extends Command
{
    protected $signature = 'kb:knowledge {operation : versions|template|draft|generate|activate|disable|preview|reprocess|runs|search|read} {project}
        {--tenant=default} {--actor= : Required user id; permissions are checked} {--file= : JSON containing description and blocks or a reprocess selection}
        {--description=} {--revision=} {--query=} {--namespace=} {--entity=} {--documents=*} {--cursor=}';
    protected $description = 'Manage project knowledge specifications and inspect authorized knowledge using shared services';

    public function handle(KnowledgeSpecificationService $specs, KnowledgeReadService $reader, KnowledgeReprocessing $reprocessing): int
    {
        $tenant = (string) $this->option('tenant');
        $project = (string) $this->argument('project');
        $actor = User::find($this->option('actor'));
        if (! $actor || ! app(OAuthAccessPolicy::class)->canUseTenant($actor, $tenant)) { $this->error('An authorized tenant actor is required.'); return self::FAILURE; }
        $operation = $this->argument('operation');
        if (! in_array($operation, ['search', 'read'], true) && ! $actor->hasAnyRole(['admin', 'super-admin'])) { $this->error('Administrator role required.'); return self::FAILURE; }
        $previous = app(TenantContext::class)->current();
        $previousActor = auth()->user();
        app(TenantContext::class)->set($tenant);
        auth()->setUser($actor);
        try {
            $file = $this->option('file');
            $input = $file ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
            $result = match ($operation) {
                'versions' => $specs->versions($tenant, $project), 'template' => app(KnowledgeContract::class)->template(),
                'draft' => $specs->draft($tenant, $project, $input['description'] ?? '', $input['blocks'] ?? [], (string) $actor->id),
                'generate' => $specs->generate((string) $this->option('description')),
                'activate' => $specs->activate($tenant, $project, (int) $this->option('revision')),
                'disable' => $this->disable($specs, $tenant, $project),
                'reprocess' => $reprocessing->select($tenant, $project, $input, $actor),
                'runs' => $reprocessing->runs($tenant, $project),
                'preview' => $this->preview($tenant, $project, $actor, $specs),
                'search' => $reader->search($tenant, $project, (string) $this->option('query'), $actor, $this->option('namespace')),
                'read' => $reader->context($tenant, $project, (int) $this->option('entity'), $actor, $this->option('cursor') === null ? null : (int) $this->option('cursor')),
                default => throw new \InvalidArgumentException('Unknown operation.'),
            };
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } finally {
            app(TenantContext::class)->set($previous);
            if ($previousActor) auth()->setUser($previousActor); else auth()->forgetUser();
        }
    }

    private function disable(KnowledgeSpecificationService $specs, string $tenant, string $project): array
    {
        $specs->disable($tenant, $project);
        return ['enabled' => false];
    }

    private function preview(string $tenant, string $project, User $actor, KnowledgeSpecificationService $specs): array
    {
        $revision = KnowledgeSpecification::query()->forTenant($tenant)->whereIn('project_key', [$project, '*'])->findOrFail($this->option('revision'));
        $contract = $specs->effective($tenant, $project, $revision);
        $out = [];
        foreach (array_slice($this->option('documents'), 0, 5) as $id) {
            $doc = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->findOrFail($id);
            if (! $actor->hasDocumentAccess($doc, 'view')) throw new \RuntimeException('Document unavailable.');
            $out[] = app(KnowledgeEnrichment::class)->preview($doc, $contract);
        }
        return $out;
    }
}
