<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\InviteCampaign;
use App\Models\InviteCode;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * The shared core for campaign + code administration (R44: the PHP /
 * Artisan, HTTP API, and MCP surfaces all delegate here — never reimplement).
 *
 * Every operation is tenant-scoped through TenantContext (R30). Code issuance
 * delegates to CodeGenerator so the Crockford / collision / normalization
 * guarantees hold on every surface; this service only binds the campaign
 * context and applies its defaults.
 */
final class CampaignService
{
    public function __construct(
        private readonly CodeGenerator $generator,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCampaign(array $attributes): InviteCampaign
    {
        return InviteCampaign::create($this->scoped($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCampaign(InviteCampaign $campaign, array $attributes): InviteCampaign
    {
        // tenant_id is immutable on update — never let a payload move a
        // campaign across tenants (orphan-pivot / cross-tenant guard).
        unset($attributes['tenant_id']);
        $campaign->update($attributes);

        return $campaign->refresh();
    }

    /**
     * Issue $count codes under a campaign (or standalone when null). Inherits
     * max_uses / expiry defaults from the campaign type when not overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return Collection<int, InviteCode>
     */
    public function issueCodes(?InviteCampaign $campaign, int $count, array $overrides = []): Collection
    {
        $attrs = $this->codeAttributes($campaign, $overrides);
        $length = isset($overrides['length']) ? (int) $overrides['length'] : null;

        return collect($this->generator->generateBatch($count, $attrs, $length));
    }

    /**
     * Revoke a code (admin action). Idempotent: a code already in a terminal
     * state stays there. Returns the refreshed code.
     */
    public function revokeCode(InviteCode $code): InviteCode
    {
        if (! in_array($code->state, [InviteCode::STATE_EXPIRED, InviteCode::STATE_REVOKED], true)) {
            $code->update(['state' => InviteCode::STATE_REVOKED]);
        }

        return $code->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function codeAttributes(?InviteCampaign $campaign, array $overrides): array
    {
        $maxUses = $overrides['max_uses'] ?? ($campaign?->type === InviteCampaign::TYPE_SINGLE_USE ? 1 : ($overrides['max_uses'] ?? 1));

        $attrs = [
            'campaign_id' => $campaign?->id,
            'max_uses' => (int) $maxUses,
            'issuer_id' => $overrides['issuer_id'] ?? null,
            'expires_at' => $overrides['expires_at'] ?? null,
        ];

        return $this->scoped($attrs);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function scoped(array $attributes): array
    {
        // BelongsToTenant auto-fills on create, but pin it explicitly so the
        // value is deterministic regardless of call context.
        $attributes['tenant_id'] ??= $this->tenant->current();

        return $attributes;
    }
}
