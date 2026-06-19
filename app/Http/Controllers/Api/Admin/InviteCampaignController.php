<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\InviteCampaignStoreRequest;
use App\Http\Resources\Admin\InviteCampaignResource;
use App\Models\InviteCampaign;
use App\Services\Invite\CampaignService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin campaign management (R44 — HTTP surface over CampaignService).
 * Mounted under the role:admin|super-admin admin group; every query is
 * tenant-scoped (R30).
 */
final class InviteCampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(): JsonResponse
    {
        $campaigns = InviteCampaign::query()
            ->forTenant($this->tenant->current())
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => InviteCampaignResource::collection($campaigns),
        ]);
    }

    public function store(InviteCampaignStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= InviteCampaign::STATUS_DRAFT;
        $data['created_by'] = $request->user()->id;

        $campaign = $this->campaigns->createCampaign($data);

        return (new InviteCampaignResource($campaign))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => new InviteCampaignResource($this->findOr404($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = $this->findOr404($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:draft,active,paused,ended'],
            'max_redemptions_total' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['sometimes', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reward_policy' => ['nullable', 'array'],
            'grant' => ['nullable', 'array'],
            'grant.role' => ['nullable', 'string', 'exists:roles,name', 'not_in:super-admin'],
            'grant.projects' => ['nullable', 'array'],
            'grant.projects.*' => ['string', 'max:120'],
            'grant.project_role' => ['nullable', 'in:member,admin,owner'],
            'grant.scope_allowlist' => ['nullable', 'array'],

            // Multi-tenant grant (one OR MORE tenants) — mirrors the store path.
            'grant.tenants' => ['nullable', 'array'],
            'grant.tenants.*.tenant_id' => ['required', 'string', 'max:50'],
            'grant.tenants.*.role' => ['nullable', 'string', 'exists:roles,name', 'not_in:super-admin'],
            'grant.tenants.*.projects' => ['nullable', 'array'],
            'grant.tenants.*.projects.*' => ['string', 'max:120'],
            'grant.tenants.*.project_role' => ['nullable', 'in:member,admin,owner'],
            'grant.tenants.*.scope_allowlist' => ['nullable', 'array'],
        ], [
            'grant.role.not_in' => 'super-admin cannot be granted through an invite code.',
            'grant.tenants.*.role.not_in' => 'super-admin cannot be granted through an invite code.',
        ]);

        return response()->json([
            'data' => new InviteCampaignResource($this->campaigns->updateCampaign($campaign, $data)),
        ]);
    }

    private function findOr404(int $id): InviteCampaign
    {
        $campaign = InviteCampaign::query()
            ->forTenant($this->tenant->current())
            ->find($id);

        if ($campaign === null) {
            throw new NotFoundHttpException('Campaign not found.');
        }

        return $campaign;
    }
}
