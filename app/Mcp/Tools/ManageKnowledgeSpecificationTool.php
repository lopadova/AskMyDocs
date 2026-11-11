<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSpecification;
use App\Services\Kb\Knowledge\KnowledgeEnrichment;
use App\Services\Kb\Knowledge\KnowledgeReprocessing;
use App\Services\Kb\Knowledge\KnowledgeSpecificationService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Administer versioned project knowledge specifications. Requires administrator and mcp:tools:write. Create immutable drafts, generate from Italian description, preview selected document IDs, activate, stop enrichment, selectively reprocess or inspect versions/runs. Activation never reprocesses history.')]
final class ManageKnowledgeSpecificationTool extends Tool
{
    protected string $name = 'manage_knowledge_specification';

    public function schema(JsonSchema $schema): array
    {
        return ['project_key' => $schema->string()->required(),
            'operation' => $schema->string()->enum(['versions', 'draft', 'generate', 'preview', 'activate', 'disable', 'reprocess', 'runs'])->required(),
            'revision_id' => $schema->integer()->min(1),
            'payload_json' => $schema->string()->description('JSON object. draft: {description,blocks}; generate: {description}; preview: {document_ids:[...]}; reprocess: {document_ids,source_types,path_prefix,after_id,limit}.')];
    }

    public function handle(Request $request, KnowledgeSpecificationService $specs, KnowledgeReprocessing $reprocessing): Response
    {
        abort_unless(auth()->user()?->hasAnyRole(['admin', 'super-admin']), 403);
        // A connected OAuth workspace has read/ingest grants, not specification-administration authority.
        abort_if(request()->attributes->has('mcp.connection'), 403);
        $tenant = app(TenantContext::class)->current();
        $data = Validator::make($request->all(), ['project_key' => 'required|string|max:128',
            'operation' => 'required|in:versions,draft,generate,preview,activate,disable,reprocess,runs',
            'revision_id' => 'sometimes|integer|min:1', 'payload_json' => 'sometimes|string|max:64000'])->validate();
        $project = $data['project_key'];
        $payload = json_decode($data['payload_json'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $payload = Validator::make($payload, ['description' => 'sometimes|string|max:16000', 'blocks' => 'sometimes|array',
            'document_ids' => 'sometimes|array|max:5', 'document_ids.*' => 'integer|min:1',
            'source_types' => 'sometimes|array', 'path_prefix' => 'sometimes|string|max:500', 'after_id' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:500'])->validate();
        $revision = (int) ($data['revision_id'] ?? 0);
        $actor = auth()->user();
        $result = match ($data['operation']) {
            'versions' => $specs->versions($tenant, $project),
            'draft' => $specs->draft($tenant, $project, $payload['description'] ?? '', $payload['blocks'] ?? [], (string) $actor->id),
            'generate' => $specs->generate($payload['description'] ?? ''),
            'activate' => $specs->activate($tenant, $project, $revision),
            'disable' => $this->disable($specs, $tenant, $project),
            'preview' => $this->preview($tenant, $project, $revision, $payload['document_ids'] ?? [], $specs),
            'reprocess' => $reprocessing->select($tenant, $project, $payload, $actor),
            'runs' => $reprocessing->runs($tenant, $project),
        };
        return Response::json($result);
    }

    private function disable(KnowledgeSpecificationService $specs, string $tenant, string $project): array
    {
        $specs->disable($tenant, $project);
        return ['enabled' => false];
    }

    private function preview(string $tenant, string $project, int $revision, array $ids, KnowledgeSpecificationService $specs): array
    {
        abort_if($project === '*', 422);
        $spec = KnowledgeSpecification::query()->forTenant($tenant)->whereIn('project_key', [$project, '*'])->findOrFail($revision);
        $contract = $specs->effective($tenant, $project, $spec);
        $result = [];
        foreach ($ids as $id) {
            $doc = KnowledgeDocument::query()->forTenant($tenant)->where('project_key', $project)->where('status', 'active')->findOrFail($id);
            abort_unless(auth()->user()->hasDocumentAccess($doc), 404);
            $result[] = app(KnowledgeEnrichment::class)->preview($doc, $contract);
        }
        return ['preview' => $result, 'persisted' => false];
    }
}
