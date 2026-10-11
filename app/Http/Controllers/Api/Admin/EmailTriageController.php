<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\EmailTriageCampaign;
use App\Models\EmailTriageCampaignItem;
use App\Models\EmailTriageEvaluation;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use App\Models\KnowledgeDocument;
use App\Services\Admin\Connectors\ConnectorInstallationService;
use App\Services\EmailTriage\EmailTriageService;
use App\Services\EmailTriage\TriageCampaigns;
use App\Services\EmailTriage\TriageEngine;
use App\Services\EmailTriage\TriageProfiles;
use App\Services\EmailTriage\TriageAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class EmailTriageController extends Controller
{
    public function __construct(private readonly ConnectorInstallationService $installations,
        private readonly TriageProfiles $profiles, private readonly EmailTriageService $triage,
        private readonly TriageCampaigns $campaigns, private readonly TriageEngine $engine) {}

    private function installation(int $id)
    {
        $installation = $this->installations->findOr404($id);
        abort_unless($this->profiles->supports($installation), 404);

        return $installation;
    }

    public function show(int $installationId): JsonResponse
    {
        $installation = $this->installation($installationId);
        $profile = $this->profiles->current($installation);
        $messages = EmailTriageMessage::query()->forTenant((string) $installation->tenant_id)->where('connector_installation_id', $installationId);
        $counts = (clone $messages)->selectRaw('status, count(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $campaign = EmailTriageCampaign::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)->latest('id')->first();
        $versions = EmailTriageProfile::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)->latest('revision')->limit(20)->get(['id', 'revision', 'enabled', 'model', 'created_at']);
        $recent = (clone $messages)->latest('id')->limit(30)->get();
        $evaluations = EmailTriageEvaluation::query()->forTenant((string) $installation->tenant_id)
            ->whereIn('id', $recent->pluck('evaluation_id')->filter())->get()->keyBy('id');
        $indexedProfiles = KnowledgeDocument::query()->forTenant((string) $installation->tenant_id)
            ->whereIn('id', $recent->pluck('knowledge_document_id')->filter())->get(['id', 'metadata'])
            ->mapWithKeys(fn ($doc) => [$doc->id => data_get($doc->metadata, 'email_triage.profile_id')]);
        $revisions = EmailTriageProfile::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)
            ->whereIn('id', $indexedProfiles->filter()->values())->pluck('revision', 'id');

        return response()->json(['data' => ['profile' => $profile, 'versions' => $versions, 'readiness' => $this->profiles->readiness(),
            'current_model' => config('decisions.models.jev.model'),
            'counts' => $counts, 'campaign' => $campaign ? $this->campaignStatus($campaign) : null,
            'sample_documents' => $this->campaigns->documents($installation, ['state' => 'all'], (int) $profile?->id)
                ->latest('id')->limit(30)->get(['id', 'title']),
            'messages' => $recent->map(function ($message) use ($profile, $evaluations, $indexedProfiles, $revisions) {
                $evaluation = $evaluations->get($message->evaluation_id);

                return ['id' => $message->id, 'subject' => $message->title, 'status' => $message->status,
                    'stale' => (int) $message->profile_id !== (int) $profile?->id || $profile?->model !== config('decisions.models.jev.model'),
                    'coverage' => $evaluation?->coverage, 'index_status' => $evaluation?->index_status,
                    'indexed_revision' => $revisions->get($indexedProfiles->get($message->knowledge_document_id)),
                    'error_code' => $evaluation?->error_code];
            })]]);
    }

    public function save(Request $request, int $installationId): JsonResponse
    {
        $profile = $this->profiles->save($this->installation($installationId), $request->all());

        return response()->json(['data' => $profile]);
    }

    public function preview(Request $request, int $installationId): JsonResponse
    {
        $installation = $this->installation($installationId);
        $validated = $request->validate(['document_id' => 'required|integer', 'profile' => 'sometimes|array']);
        $current = $this->profiles->current($installation);
        $profile = isset($validated['profile'])
            ? new EmailTriageProfile($this->profiles->validate($validated['profile']) + ['revision' => ($current?->revision ?? 0) + 1, 'model' => config('decisions.models.jev.model')])
            : $current;
        abort_unless($profile && collect($profile->rules)->contains(fn ($r) => $r['enabled']), 422, 'Configure a triage profile first.');
        $document = $this->campaigns->documents($installation, ['state' => 'all'], (int) $profile->id)->findOrFail($validated['document_id']);
        $body = $this->campaigns->body($document);
        $message = EmailTriageMessage::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)->where('identity_hash', $this->triage->identity($document->metadata))->first();
        // Preview uses existing files directly; it never stages sources or dispatches an ingest.
        $manifest = $message?->manifest ?? $this->campaigns->previewAttachments($document);
        $prepared = $this->engine->prepare($body, $manifest, (string) ($document->metadata['disk'] ?? config('kb.sources.disk', 'local')));

        $result = $this->engine->analyze($profile, $prepared);
        app(TriageAudit::class)->record($installation, 'preview', ['document_id' => $document->id,
            'latency_ms' => $result['latency_ms'], 'usage' => $result['usage']]);

        return response()->json(['data' => $result]);
    }

    public function campaignPreview(Request $request, int $installationId): JsonResponse
    {
        return response()->json(['data' => $this->campaigns->preview($this->installation($installationId), $this->filters($request))]);
    }

    public function startCampaign(Request $request, int $installationId): JsonResponse
    {
        $installation = $this->installation($installationId);
        $profile = $this->profiles->current($installation);
        $request->validate(['profile_id' => 'required|integer']);
        abort_unless((int) $request->input('profile_id') === (int) $profile?->id, 409, 'Rules changed after preview. Refresh the preview.');
        $campaign = $this->campaigns->start($installation, $this->filters($request));

        return response()->json(['data' => $this->campaignStatus($campaign)], 202);
    }

    public function controlCampaign(Request $request, int $installationId, int $campaignId): JsonResponse
    {
        $installation = $this->installation($installationId);
        $campaign = EmailTriageCampaign::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)->findOrFail($campaignId);
        $data = $request->validate(['action' => 'required|in:pause,resume']);
        abort_unless(in_array($campaign->status, ['running', 'paused'], true), 422, 'The campaign has already finished.');
        $campaign->forceFill(['status' => $data['action'] === 'pause' ? 'paused' : 'running'])->save();
        if ($data['action'] === 'resume') {
            $messages = EmailTriageCampaignItem::query()->forTenant((string) $installation->tenant_id)
                ->where('campaign_id', $campaignId)->where('status', 'queued')->select('message_id');
            EmailTriageEvaluation::query()->forTenant((string) $installation->tenant_id)->whereIn('message_id', $messages)
                ->whereNull('indexed_at')->whereIn('status', ['waiting', 'completed'])
                ->update(['queued_at' => null]);
        }
        app(TriageAudit::class)->record($installation, 'campaign_'.$data['action'], ['campaign_id' => $campaign->id]);

        return response()->json(['data' => $this->campaignStatus($campaign)]);
    }

    public function retry(int $installationId, int $messageId): JsonResponse
    {
        $installation = $this->installation($installationId);
        $message = EmailTriageMessage::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)->findOrFail($messageId);
        $this->triage->retry($message);
        app(TriageAudit::class)->record($installation, 'retry', ['message_id' => $message->id, 'evaluation_id' => $message->evaluation_id]);
        EmailTriageCampaignItem::query()->forTenant($message->tenant_id)->where('message_id', $message->id)
            ->where('status', 'failed')->update(['status' => 'queued', 'error_code' => null]);

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    public function retryCampaignItem(int $installationId, int $campaignId, int $itemId): JsonResponse
    {
        $installation = $this->installation($installationId);
        $campaign = EmailTriageCampaign::query()->forTenant((string) $installation->tenant_id)
            ->where('connector_installation_id', $installationId)->findOrFail($campaignId);
        $item = EmailTriageCampaignItem::query()->forTenant((string) $installation->tenant_id)
            ->where('campaign_id', $campaignId)->findOrFail($itemId);
        abort_unless(in_array($item->status, ['failed', 'unavailable'], true), 422, 'This item is not in error.');
        if ($item->message_id) {
            $message = EmailTriageMessage::query()->forTenant((string) $installation->tenant_id)
                ->where('connector_installation_id', $installationId)->findOrFail($item->message_id);
            $this->triage->retry($message, $campaign->profile_id);
        } else {
            $document = $this->campaigns->documents($installation, ['state' => 'all'], $campaign->profile_id)->findOrFail($item->knowledge_document_id);
            $profile = EmailTriageProfile::query()->forTenant((string) $installation->tenant_id)->findOrFail($campaign->profile_id);
            $message = $this->campaigns->stage($installation, $profile, $document);
        }
        $item->forceFill(['message_id' => $message->id, 'status' => 'queued', 'error_code' => null])->save();
        if ($campaign->status === 'completed') {
            $campaign->forceFill(['status' => 'running'])->save();
        }
        app(TriageAudit::class)->record($installation, 'item_retry', ['campaign_id' => $campaign->id, 'item_id' => $item->id, 'message_id' => $message->id]);

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['project' => 'nullable|string|max:255', 'mailbox' => 'nullable|string|max:255',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'state' => 'required|in:never,stale,failed,all']);
    }

    private function campaignStatus(EmailTriageCampaign $campaign): array
    {
        return $campaign->toArray() + ['items' => EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)
            ->where('campaign_id', $campaign->id)->whereIn('status', ['failed', 'unavailable'])->latest('id')->limit(30)
            ->get(['id', 'knowledge_document_id', 'status', 'error_code']),
            'counts' => EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)
            ->where('campaign_id', $campaign->id)->selectRaw('status, count(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')];
    }
}
