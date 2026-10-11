<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use App\Models\EmailTriageCampaign;
use App\Models\EmailTriageCampaignItem;
use App\Models\EmailTriageEvaluation;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

final class TriageCampaigns
{
    public function __construct(private readonly EmailTriageService $triage, private readonly TriageProfiles $profiles) {}

    public function documents(ConnectorInstallation $installation, array $filters, int $profileId): Builder
    {
        $query = KnowledgeDocument::query()->forTenant((string) $installation->tenant_id)
            ->where('status', 'active')->where('metadata->connector', 'imap')
            ->where('metadata->installation_id', $installation->id)->whereNull('metadata->attachment_filename');
        foreach (['project' => 'project_key', 'mailbox' => 'metadata->imap_mailbox'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('metadata->imap->date', '>=', $filters['from'].'T00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('metadata->imap->date', '<', \Carbon\Carbon::parse($filters['to'])->addDay()->format('Y-m-d').'T00:00:00');
        }
        return match ($filters['state'] ?? 'never') {
            'never' => $query->whereNull('metadata->email_triage->evaluation_id'),
            'stale' => $query->whereNotNull('metadata->email_triage->evaluation_id')
                ->where('metadata->email_triage->profile_id', '!=', $profileId),
            'failed' => $query->whereIn('id', EmailTriageMessage::query()->forTenant((string) $installation->tenant_id)
                ->where('connector_installation_id', $installation->id)->whereIn('evaluation_id',
                    EmailTriageEvaluation::query()->forTenant((string) $installation->tenant_id)
                        ->where(fn ($q) => $q->where('status', 'failed')->orWhere('index_status', 'failed'))->select('id'))
                ->select('knowledge_document_id')),
            default => $query,
        };
    }

    /** Complete body only: original snapshot, source, or verified conversion artifact. */
    public function body(KnowledgeDocument $document): string
    {
        $metadata = (array) $document->metadata;
        if (isset($metadata['imap_doc_key'])) {
            $message = EmailTriageMessage::query()->forTenant($document->tenant_id)
                ->where('connector_installation_id', $metadata['installation_id'] ?? 0)
                ->where('identity_hash', $this->triage->identity($metadata))->first();
            if ($message?->original_path) {
                return $this->triage->original($message);
            }
        }
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'local'));
        $path = $this->triage->fullPath((string) ($metadata['prefix'] ?? config('kb.sources.path_prefix', '')), $document->source_path);
        if (Storage::disk($disk)->exists($path)) {
            $bytes = Storage::disk($disk)->get($path);
            if (is_string($bytes) && $bytes !== '') {
                return $bytes;
            }
        }
        if ($document->markdown_path && $document->content_hash
            && app(ConversionArtifactStore::class)->verifies($disk, $document->markdown_path, $document->content_hash)) {
            $bytes = app(ConversionArtifactStore::class)->read($disk, $document->markdown_path);
            if (! isset($metadata['email_triage'])) {
                return $bytes;
            }
        }
        throw new \RuntimeException('missing_complete_email_source');
    }

    public function preview(ConnectorInstallation $installation, array $filters): array
    {
        $profile = $this->profiles->current($installation);
        $query = $this->documents($installation, $filters, (int) $profile?->id);
        $total = (clone $query)->count();
        $available = $unavailable = 0;
        // Keep the HTTP preview bounded even for a mailbox containing 140k messages.
        foreach ((clone $query)->orderBy('id')->limit(100)->get() as $document) {
            try {
                $this->body($document);
                $available++;
            } catch (\Throwable) {
                $unavailable++;
            }
        }
        $observed = EmailTriageEvaluation::query()->forTenant((string) $installation->tenant_id)
            ->where('profile_id', $profile?->id)->whereNotNull('usage')->orderByDesc('id')->limit(100)->get();
        $costs = $observed->map(fn ($e) => $e->usage['cost'] ?? null)->filter(fn ($cost) => is_numeric($cost));
        $decisionRate = $costs->isNotEmpty() ? $costs->avg() : config('email-triage.estimated_decision_usd_per_email');
        $indexRate = config('email-triage.estimated_reindex_usd_per_email');

        return ['total' => $total, 'availability_sample' => ['checked' => $available + $unavailable,
            'available' => $available, 'unavailable' => $unavailable, 'unchecked' => max(0, $total - $available - $unavailable)],
            'estimated_decision_usd' => is_numeric($decisionRate) ? round($total * $decisionRate, 6) : null,
            'estimated_reindex_usd' => is_numeric($indexRate) ? round($total * $indexRate, 6) : null,
            'decision_estimate_basis' => $costs->isNotEmpty() ? 'observed_usage' : 'configured_rate',
            'filters' => $filters, 'profile_id' => $profile?->id];
    }

    public function start(ConnectorInstallation $installation, array $filters): EmailTriageCampaign
    {
        $profile = $this->profiles->current($installation);
        abort_unless($profile?->enabled, 422, 'Enable and save a triage profile first.');
        return DB::transaction(function () use ($installation, $filters, $profile) {
            ConnectorInstallation::query()->where('tenant_id', $installation->tenant_id)->lockForUpdate()->findOrFail($installation->id);
            $existing = EmailTriageCampaign::query()->forTenant((string) $installation->tenant_id)
                ->where('connector_installation_id', $installation->id)->whereIn('status', ['running', 'paused'])->first();
            abort_if($existing, 409, 'A campaign is already active for this account.');

            $campaign = EmailTriageCampaign::create(['tenant_id' => $installation->tenant_id,
                'connector_installation_id' => $installation->id, 'profile_id' => $profile->id,
                'filters' => $filters, 'cutoff_id' => (int) $this->documents($installation, $filters, $profile->id)->max('id')]);
            app(TriageAudit::class)->record($installation, 'campaign_started', ['campaign_id' => $campaign->id, 'profile_id' => $profile->id]);

            return $campaign;
        });
    }

    public function pump(EmailTriageCampaign $campaign): void
    {
        Cache::lock('email-triage:campaign:'.$campaign->tenant_id.':'.$campaign->id, 180)->get(function () use ($campaign): void {
            $campaign->refresh();
            if ($campaign->status !== 'running') {
                return;
            }
            $installation = ConnectorInstallation::query()->where('tenant_id', $campaign->tenant_id)->find($campaign->connector_installation_id);
            if (! $installation || $installation->status === ConnectorInstallation::STATUS_DISABLED
                || ! $this->profiles->current($installation)?->enabled) {
                $campaign->forceFill(['status' => 'paused'])->save();
                return;
            }
            $profile = EmailTriageProfile::query()->forTenant($campaign->tenant_id)->findOrFail($campaign->profile_id);
            $documents = $this->documents($installation, $campaign->filters, $profile->id)
                ->where('id', '>', $campaign->cursor)->where('id', '<=', $campaign->cutoff_id)
                ->orderBy('id')->limit((int) config('email-triage.campaign_batch_size', 50))->get();
            foreach ($documents as $document) {
                if ($campaign->fresh()->status !== 'running') {
                    return;
                }
                $item = EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)->firstOrCreate([
                    'campaign_id' => $campaign->id, 'knowledge_document_id' => $document->id,
                ], ['tenant_id' => $campaign->tenant_id]);
                if ($item->status === 'waiting') {
                    try {
                        $message = $this->stage($installation, $profile, $document);
                        $item->forceFill(['message_id' => $message->id, 'status' => 'queued'])->save();
                    } catch (\Throwable $exception) {
                        $code = TriageFailure::code($exception);
                        $item->forceFill(['status' => 'unavailable', 'error_code' => $code])->save();
                        Log::warning('Email triage campaign staging failed', ['tenant_id' => $campaign->tenant_id,
                            'campaign_id' => $campaign->id, 'item_id' => $item->id, 'document_id' => $document->id,
                            'error_code' => $code, 'error_location' => str_replace(base_path().'/', '', $exception->getFile()).':'.$exception->getLine()]);
                    }
                }
                $campaign->forceFill(['cursor' => $document->id])->save();
            }
            $indexedMessages = EmailTriageMessage::query()->forTenant($campaign->tenant_id)->whereIn('evaluation_id',
                EmailTriageEvaluation::query()->forTenant($campaign->tenant_id)->whereNotNull('indexed_at')->select('id'))->select('id');
            EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)
                ->where('status', 'queued')->whereIn('message_id', $indexedMessages)->update(['status' => 'completed']);
            $failedMessages = EmailTriageMessage::query()->forTenant($campaign->tenant_id)->whereIn('evaluation_id',
                EmailTriageEvaluation::query()->forTenant($campaign->tenant_id)
                    ->where(fn ($q) => $q->where('status', 'failed')->orWhere('index_status', 'failed'))->select('id'))->select('id');
            EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)
                ->where('status', 'queued')->whereIn('message_id', $failedMessages)
                ->update(['status' => 'failed', 'error_code' => 'triage_or_index_failed']);
            $campaign->forceFill([
                'selected' => EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)->count(),
                'unavailable' => EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)->where('status', 'unavailable')->count(),
            ])->save();
            if ($documents->isEmpty() && ! EmailTriageCampaignItem::query()->forTenant($campaign->tenant_id)
                ->where('campaign_id', $campaign->id)->whereIn('status', ['waiting', 'queued'])->exists()) {
                $campaign->forceFill(['status' => 'completed'])->save();
            }
        });
    }

    public function stage(ConnectorInstallation $installation, EmailTriageProfile $profile, KnowledgeDocument $document): EmailTriageMessage
    {
        $body = $this->body($document);
        $metadata = $document->metadata;
        // The new revision owns its tags; never carry old automatic tags into a re-evaluation.
        $old = data_get($metadata, 'email_triage.evaluation_id');
        if ($old) {
            data_set($metadata, '_derived.search_tags', (array) data_get($metadata, 'email_triage.base_search_tags', []));
        }
        unset($metadata['email_triage']);
        $metadata['access_scope'] = $document->access_scope;
        $metadata['previous_document_id'] = $document->id;
        $identity = $this->triage->identity($metadata);
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'local'));
        $prefix = (string) ($metadata['prefix'] ?? config('kb.sources.path_prefix', ''));
        $hash = hash('sha256', $body);
        $snapshot = $this->triage->fullPath($prefix, '.artifacts/email-triage/'.$document->tenant_id.'/'.$identity.'/'.$hash.'.source');
        app(ConversionArtifactStore::class)->assertContainedOnDisk($disk, $snapshot);
        if (! Storage::disk($disk)->exists($snapshot) && ! Storage::disk($disk)->put($snapshot, $body)) {
            throw new \RuntimeException('Cannot preserve the historical email.');
        }
        if ($this->triage->hashFile($disk, $snapshot) !== $hash) {
            throw new \RuntimeException('The historical email snapshot is corrupt.');
        }
        $existing = EmailTriageMessage::query()->forTenant($document->tenant_id)->where('connector_installation_id', $installation->id)->where('identity_hash', $identity)->first();
        $manifest = $existing && ! collect($existing->manifest)->contains(fn ($file) => ($file['status'] ?? '') === 'pending')
            ? $existing->manifest : $this->historicalAttachments($document, $disk, $prefix);

        return DB::transaction(function () use ($document, $installation, $identity, $profile, $disk, $prefix, $snapshot, $hash, $metadata, $manifest) {
            $message = EmailTriageMessage::query()->forTenant($document->tenant_id)->updateOrCreate([
                'connector_installation_id' => $installation->id, 'identity_hash' => $identity,
            ], ['tenant_id' => $document->tenant_id, 'project_key' => $document->project_key, 'source_path' => $document->source_path,
                'disk' => $disk, 'prefix' => $prefix, 'original_path' => $snapshot, 'original_hash' => $hash,
                'title' => $document->title, 'metadata' => $metadata, 'manifest' => $manifest, 'profile_id' => $profile->id,
                'knowledge_document_id' => $document->id, 'status' => 'waiting']);
            $this->triage->ready($message);

            return $message;
        });
    }

    public function previewAttachments(KnowledgeDocument $document): array
    {
        return $this->historicalAttachments($document,
            (string) ($document->metadata['disk'] ?? config('kb.sources.disk', 'local')),
            (string) ($document->metadata['prefix'] ?? config('kb.sources.path_prefix', '')), false);
    }

    private function historicalAttachments(KnowledgeDocument $document, string $disk, string $prefix, bool $preserve = true): array
    {
        $metadata = $document->metadata;
        $files = [];
        $attachments = KnowledgeDocument::query()->forTenant($document->tenant_id)->where('status', 'active')
            ->where('metadata->installation_id', $metadata['installation_id'])
            ->where('metadata->imap_doc_key', $metadata['imap_doc_key'])->whereNotNull('metadata->attachment_filename')->get();
        foreach ($attachments as $attachment) {
            $file = ['name' => $attachment->metadata['attachment_filename'], 'mime' => $attachment->mime_type, 'status' => 'missing'];
            $attachmentDisk = (string) ($attachment->metadata['disk'] ?? $disk);
            $path = $this->triage->fullPath((string) ($attachment->metadata['prefix'] ?? $prefix), $attachment->source_path);
            if ($attachmentDisk === $disk && Storage::disk($disk)->exists($path)) {
                if (Storage::disk($disk)->size($path) <= (int) config('email-triage.extraction_max_bytes', 5242880)) {
                    $file['hash'] = $this->triage->hashFile($disk, $path);
                    $file['snapshot'] = $preserve ? $this->triage->snapshot($document->tenant_id, $this->triage->identity($metadata), $disk, $prefix, $path, $file['hash']) : $path;
                    $file['status'] = 'saved';
                } else {
                    $file['status'] = 'extraction_size_limit';
                }
            } elseif ($attachment->markdown_path && $attachment->content_hash) {
                $cached = app(AttachmentExcerpt::class)->cached($attachmentDisk, $attachment->markdown_path, $attachment->content_hash);
                $file['reason'] = $cached['reason'];
                $file['status'] = $cached['text'] !== '' ? 'saved' : $cached['reason'];
                if ($cached['text'] !== '') {
                    $file['excerpt'] = $cached['text'];
                    $file['hash'] = $attachment->content_hash;
                }
            }
            $files[] = $file;
        }
        $unaccounted = max(0, (int) data_get($metadata, 'imap.attachment_count', 0) - count($files));
        if ($unaccounted) {
            $files[] = ['name' => $unaccounted.' additional attachment(s)', 'status' => 'unavailable', 'reason' => 'not_imported_or_not_preserved'];
        }

        return $files;
    }
}
