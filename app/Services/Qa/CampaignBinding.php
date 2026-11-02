<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CampaignBinding
{
    public function bind(Request $request, Conversation $conversation, array $qa, array $input): array
    {
        abort_unless($request->routeIs('api.chat.*') && $request->bearerToken() !== null
            && $request->user()->tokenCan('chat:debug') && $request->user()->hasAnyRole(['admin', 'super-admin']), 403);
        try { app(CampaignLedger::class)->assertEnabled(); } catch (\DomainException) { abort(404); }
        $campaign = app(CampaignLedger::class)->owned($qa['campaign_id'], $conversation->tenant_id,
            (string) $conversation->project_key, (int) $request->user()->id);
        if ($campaign->status !== 'active') {
            throw ValidationException::withMessages(['qa' => 'qa_campaign_stopped']);
        }
        $release = config('agent.diagnostics.release');
        if (! is_string($release) || ! preg_match('/^[a-f0-9]{7,40}$/D', $release)) {
            throw ValidationException::withMessages(['qa' => 'qa_release_required']);
        }
        // Omission never broadens the campaign's explicit source allowlist.
        foreach (['api', 'mcp'] as $kind) {
            $selected = $input['live_sources'][$kind] ?? $campaign->manifest_json['live_sources'][$kind];
            if (array_diff($selected, $campaign->manifest_json['live_sources'][$kind]) !== []) {
                throw ValidationException::withMessages(['live_sources' => 'qa_source_outside_campaign']);
            }
            $input['live_sources'][$kind] = $selected;
        }
        return [...$input, 'qa' => [...$qa, 'release' => $release,
            'configuration' => app(CampaignRuntime::class)->snapshot($conversation->tenant_id, (string) $conversation->project_key)]];
    }
}
