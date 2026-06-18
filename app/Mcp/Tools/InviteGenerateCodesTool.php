<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\InviteCampaign;
use App\Services\Invite\CampaignService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * MCP write surface (R44, third surface) over the SAME CampaignService /
 * CodeGenerator the HTTP and PHP layers use — never a parallel implementation.
 * Mints normalized Crockford codes (collision-safe) scoped to the
 * MCP-resolved tenant (R30). Optionally binds them to a campaign.
 */
#[Description('Generate a batch of invite codes (optionally bound to a campaign). Returns the minted code strings. Codes are normalized Crockford Base32 with a UNIQUE(code) collision guard.')]
class InviteGenerateCodesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()
                ->description('How many codes to mint (1–1000).')
                ->required(),
            'campaign_key' => $schema->string()
                ->description('Optional campaign key to bind the codes to (tenant-scoped).'),
            'max_uses' => $schema->integer()
                ->description('Seats per code (default 1).'),
            'length' => $schema->integer()
                ->description('Code body length (default from config; 8 ≈ 40 bits).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $count = (int) $request->get('count');
        if ($count < 1 || $count > 1000) {
            return Response::json(['error' => 'count must be between 1 and 1000']);
        }

        $tenant = app(TenantContext::class)->current();

        $campaign = null;
        $campaignKey = $request->get('campaign_key');
        if (is_string($campaignKey) && $campaignKey !== '') {
            $campaign = InviteCampaign::query()
                ->forTenant($tenant)
                ->where('key', $campaignKey)
                ->first();

            if ($campaign === null) {
                return Response::json(['error' => 'campaign_not_found', 'campaign_key' => $campaignKey]);
            }
        }

        $codes = app(CampaignService::class)->issueCodes($campaign, $count, [
            'max_uses' => $request->get('max_uses'),
            'length' => $request->get('length'),
        ]);

        return Response::json([
            'count' => $codes->count(),
            'codes' => $codes->pluck('code')->all(),
        ]);
    }
}
