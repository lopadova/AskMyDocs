<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Qa\CampaignLedger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChatApiQaCampaignController
{
    public function __invoke(Request $request, string $campaign, CampaignLedger $ledger): JsonResponse
    {
        try { $ledger->assertDevelopment(); } catch (\DomainException) { abort(404); }
        $data = $request->validate(['project_key' => ['required', 'string', 'max:255']]);
        $record = $ledger->owned($campaign, app(TenantContext::class)->current(), $data['project_key'], $request->user()->id);
        return response()->json($ledger->summary($record))->header('Cache-Control', 'private, no-store');
    }
}
