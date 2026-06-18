<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\InviteCodeGenerateRequest;
use App\Http\Resources\Admin\InviteCodeResource;
use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Services\Invite\CampaignService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin code issuance + revocation (R44 — HTTP surface over CampaignService).
 */
final class InviteCodeController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_id' => ['nullable', 'integer'],
            'state' => ['nullable', 'in:active,redeemed,exhausted,expired,revoked'],
        ]);

        $codes = InviteCode::query()
            ->forTenant($this->tenant->current())
            ->when(isset($validated['campaign_id']), fn ($q) => $q->where('campaign_id', $validated['campaign_id']))
            ->when(isset($validated['state']), fn ($q) => $q->where('state', $validated['state']))
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json(['data' => InviteCodeResource::collection($codes)]);
    }

    public function store(InviteCodeGenerateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $campaign = null;
        if (isset($data['campaign_id'])) {
            $campaign = InviteCampaign::query()
                ->forTenant($this->tenant->current())
                ->find($data['campaign_id']);

            if ($campaign === null) {
                throw new NotFoundHttpException('Campaign not found.');
            }
        }

        $codes = $this->campaigns->issueCodes($campaign, (int) $data['count'], [
            'max_uses' => $data['max_uses'] ?? null,
            'length' => $data['length'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'issuer_id' => $request->user()->id,
        ]);

        return response()->json([
            'data' => InviteCodeResource::collection($codes),
        ], 201);
    }

    public function revoke(int $id): JsonResponse
    {
        $code = InviteCode::query()
            ->forTenant($this->tenant->current())
            ->find($id);

        if ($code === null) {
            throw new NotFoundHttpException('Code not found.');
        }

        return response()->json([
            'data' => new InviteCodeResource($this->campaigns->revokeCode($code)),
        ]);
    }
}
