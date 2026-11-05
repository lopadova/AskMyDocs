<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UpsertKbRetrievalProfileRequest;
use App\Models\KbRetrievalProfile;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Administrative configuration for recursive KB retrieval.  Profiles are
 * tenant/project scoped and intentionally never read from an untrusted source.
 */
final class KbRetrievalProfileController extends Controller
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    public function index(): JsonResponse
    {
        $tenantId = $this->tenant->current();
        $profiles = KbRetrievalProfile::query()
            ->forTenant($tenantId)
            ->orderBy('project_key')
            ->get()
            ->keyBy('project_key');

        $projects = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->select('project_key')
            ->distinct()
            ->pluck('project_key')
            ->merge($profiles->keys())
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'profiles' => $projects
                ->map(fn (string $projectKey): array => $this->entry($projectKey, $profiles->get($projectKey)))
                ->all(),
        ]);
    }

    public function upsert(UpsertKbRetrievalProfileRequest $request): JsonResponse
    {
        $data = $request->validated();
        $profile = KbRetrievalProfile::updateOrCreate(
            [
                'tenant_id' => $this->tenant->current(),
                'project_key' => $data['project_key'],
            ],
            [
                'company_context' => $data['company_context'],
                'glossary' => $data['glossary'] ?? [],
                'relevant_entities' => $data['relevant_entities'] ?? [],
                'expected_facts' => $data['expected_facts'] ?? [],
                'preferred_source_types' => $data['preferred_source_types'] ?? [],
            ],
        );

        return response()->json(['ok' => true, 'profile' => $this->entry($profile->project_key, $profile)]);
    }

    /** @return array<string, mixed> */
    private function entry(string $projectKey, ?KbRetrievalProfile $profile): array
    {
        return [
            'project_key' => $projectKey,
            'configured' => $profile !== null,
            'company_context' => $profile?->company_context ?? '',
            'glossary' => $profile?->glossary ?? [],
            'relevant_entities' => $profile?->relevant_entities ?? [],
            'expected_facts' => $profile?->expected_facts ?? [],
            'preferred_source_types' => $profile?->preferred_source_types ?? [],
            'updated_at' => $profile?->updated_at?->toIso8601String(),
        ];
    }
}
