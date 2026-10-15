<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use App\Jobs\EmailTriage\AnalyzeEmailJob;
use App\Jobs\IngestDocumentJob;
use App\Models\EmailTriageEvaluation;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use App\Models\EmailTriageCampaign;
use App\Models\EmailTriageCampaignItem;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\KbPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;

final class EmailTriageService
{
    public function __construct(private readonly TriageProfiles $profiles, private readonly TriageEngine $engine, private readonly EmailEnvelope $envelopes) {}

    /** Called before either importer writes the body, never from an IMAP diagnostic. */
    public function register(ConnectorInstallation $installation, ImapMessage $email, AttachmentPolicy $policy): void
    {
        $profile = $this->profiles->current($installation);
        if (! $profile?->enabled) {
            return;
        }
        $metadata = (new MailMetadata)->build($installation->id, $email);
        $files = [];
        $accepted = 0;
        foreach ($email->attachments as $index => $file) {
            $allowed = $accepted < max(0, $policy->limit()) && $policy->accepts($file);
            if ($allowed) {
                $accepted++;
            }
            $files[] = ['slot' => $index, 'name' => $file->filename, 'mime' => $file->mimeType,
                'hash' => $allowed ? hash('sha256', $file->contents) : null,
                'status' => $allowed ? 'pending' : 'excluded',
                'reason' => $allowed ? null : 'connector_attachment_policy'];
        }
        $tenant = (string) $installation->tenant_id;
        DB::transaction(function () use ($installation, $profile, $metadata, $files, $tenant, $email): void {
            $message = EmailTriageMessage::query()->forTenant($tenant)->firstOrCreate([
                'connector_installation_id' => $installation->id, 'identity_hash' => $this->identity($metadata),
            ], ['tenant_id' => $tenant, 'project_key' => $installation->project_key ?: 'default',
                'profile_id' => $profile->id, 'metadata' => $metadata, 'manifest' => $files]);
            // A replay fills fresh durable receipts and then reuses the same evaluation key.
            $message->forceFill(['profile_id' => $profile->id, 'manifest' => $files,
                'status' => 'awaiting_files', 'original_path' => null, 'original_hash' => null,
                'email_date' => $email->date])->save();
            Log::info('Email triage manifest registered', ['tenant_id' => $tenant, 'installation_id' => $installation->id,
                'message_id' => $message->id, 'profile_id' => $profile->id, 'attachment_count' => count($files)]);
        });
    }

    /** Synchronous v1 hook: register semantic commitments before source writes. */
    public function registerEnvelope(array $envelope): void
    {
        $installation = $this->envelopes->validate($envelope);
        $profile = $this->profiles->current($installation);
        if (! $profile?->enabled) {
            return;
        }
        $tenant = (string) $installation->tenant_id;
        $metadata = $envelope['metadata'];
        unset($metadata['access_scope'], $metadata['previous_document_id'], $metadata['email_parent_document_id'], $metadata['email_triage'],
            $metadata['generated_fixture'], $metadata['dataset_version'], $metadata['fixture_id']);
        $identity = $this->identity($metadata);
        DB::transaction(function () use ($installation, $profile, $tenant, $metadata, $identity, $envelope): void {
            // Serialize creation and revision changes with the installation row;
            // the tenant/install/identity UNIQUE index backs the same invariant.
            ConnectorInstallation::query()->where('tenant_id', $tenant)->lockForUpdate()->findOrFail($installation->id);
            $message = EmailTriageMessage::query()->forTenant($tenant)->firstOrCreate([
                'connector_installation_id' => $installation->id, 'identity_hash' => $identity,
            ], ['tenant_id' => $tenant, 'project_key' => $envelope['project_key'],
                'profile_id' => $profile->id, 'metadata' => $metadata, 'manifest' => $envelope['manifest']]);
            $sameRevision = ($message->metadata['email_revision_hash'] ?? null) === $metadata['email_revision_hash'];
            $sameProfile = (int) $message->profile_id === (int) $profile->id;
            $scopeChanged = $message->project_key !== $envelope['project_key'];
            // A completed replay cannot reopen pending slots or lose snapshots.
            // Mutable labels/read flags update presentation without another decision.
            $preserved = array_intersect_key($message->metadata, ['previous_document_id' => true, 'access_scope' => true]);
            if ($scopeChanged && $message->knowledge_document_id) {
                $previous = KnowledgeDocument::query()->forTenant($tenant)
                    ->where('project_key', $message->project_key)
                    ->where('metadata->connector', $metadata['connector'])
                    ->where('metadata->installation_id', $installation->id)
                    ->where('metadata->email_message_key', $metadata['email_message_key'])
                    ->find($message->knowledge_document_id);
                if ($previous) {
                    $preserved = ['previous_document_id' => $previous->id, 'access_scope' => $previous->access_scope];
                }
            }
            $message->forceFill(['project_key' => $envelope['project_key'], 'profile_id' => $profile->id, 'metadata' => array_replace($metadata, $preserved),
                'email_date' => $envelope['email_date']]);
            if (! $sameRevision || $scopeChanged) {
                $message->forceFill(['manifest' => $sameRevision ? $message->manifest : $envelope['manifest'],
                    'status' => 'awaiting_files', 'original_path' => null, 'original_hash' => null,
                    'evaluation_id' => $sameRevision && $sameProfile ? $message->evaluation_id : null]);
                if ($scopeChanged && $sameRevision && $sameProfile && $message->evaluation_id) {
                    $evaluation = EmailTriageEvaluation::query()->forTenant($tenant)->lockForUpdate()->findOrFail($message->evaluation_id);
                    $evaluation->forceFill(['index_generation' => (int) $evaluation->index_generation + 1,
                        'index_status' => 'waiting', 'indexed_at' => null, 'indexed_document_id' => null,
                        'index_queued_at' => null, 'queued_at' => null,
                        'error_code' => $evaluation->result ? null : $evaluation->error_code])->save();
                }
            } elseif (! $sameProfile) {
                $message->forceFill(['evaluation_id' => null, 'status' => $message->original_path ? 'waiting' : 'awaiting_files']);
            }
            $message->save();
            if ($sameRevision && ! $sameProfile && ! $scopeChanged) {
                $this->ready($message);
            }
            Log::info('Email API triage manifest registered', ['tenant_id' => $tenant,
                'installation_id' => $installation->id, 'message_id' => $message->id,
                'profile_id' => $profile->id, 'attachment_count' => count($envelope['manifest']),
                'replay' => $sameRevision]);
        });
    }

    /** Only the host can link prior projections or carry forward their scope. */
    public function trustedSourceMetadata(string $tenant, string $project, string $path, array $metadata): array
    {
        if (! EmailConnectorRegistry::isApi((string) ($metadata['connector'] ?? ''))) {
            return $metadata;
        }
        unset($metadata['access_scope'], $metadata['previous_document_id'], $metadata['email_parent_document_id'], $metadata['email_triage'],
            $metadata['generated_fixture'], $metadata['dataset_version'], $metadata['fixture_id']);
        $installation = ConnectorInstallation::query()->where('tenant_id', $tenant)->findOrFail($metadata['installation_id'] ?? 0);
        $this->envelopes->validateMetadata($metadata, $tenant, $installation);
        if ($project !== EmailConnectorRegistry::resolveProject($installation)) {
            throw new \RuntimeException('Email receipt project does not match its installation.');
        }
        $previous = KnowledgeDocument::query()->forTenant($tenant)->where('source_path', $path)->where('status', 'active')
            ->where('metadata->connector', $metadata['connector'])->where('metadata->installation_id', $installation->id)
            ->where('metadata->email_message_key', $metadata['email_message_key'])->orderByDesc('id')->first();
        if ($previous) {
            $metadata['previous_document_id'] = $previous->id;
            $metadata['access_scope'] = $previous->access_scope;
        }
        if (EmailConnectorRegistry::isAttachment($metadata)) {
            $parent = KnowledgeDocument::query()->forTenant($tenant)->where('status', 'active')
                ->where('metadata->connector', $metadata['connector'])->where('metadata->installation_id', $installation->id)
                ->where('metadata->email_message_key', $metadata['email_message_key'])
                ->whereNull('metadata->attachment_filename')->orderByDesc('id')->first();
            if ($parent) {
                $metadata['email_parent_document_id'] = $parent->id;
                $metadata['access_scope'] = $parent->access_scope;
            }
        }

        return $metadata;
    }

    /** Gate bodies and already dispatched API receipts; new attachments ingest normally. */
    public function receive(string $project, string $path, string $disk, string $title, array $metadata, string $tenant, ?string $refusal = null): bool
    {
        if (! EmailConnectorRegistry::supportsKey((string) ($metadata['connector'] ?? ''))) {
            return false;
        }
        $installation = ConnectorInstallation::query()->where('tenant_id', $tenant)->find($metadata['installation_id'] ?? 0);
        $api = EmailConnectorRegistry::isApi((string) $metadata['connector']);
        if ($api) {
            unset($metadata['access_scope'], $metadata['previous_document_id'], $metadata['email_parent_document_id'], $metadata['email_triage'],
            $metadata['generated_fixture'], $metadata['dataset_version'], $metadata['fixture_id']);
            if (! $installation) {
                throw new \RuntimeException('Email receipt installation is outside its tenant.');
            }
            $this->envelopes->validateMetadata($metadata, $tenant, $installation);
            if ($project !== EmailConnectorRegistry::resolveProject($installation)) {
                throw new \RuntimeException('Email receipt project does not match its installation.');
            }
            if (EmailConnectorRegistry::isAttachment($metadata)) {
                if (! is_string($metadata['email_attachment_id'] ?? null) || $metadata['email_attachment_id'] === ''
                    || ! is_int($metadata['email_attachment_slot'] ?? null) || $metadata['email_attachment_slot'] < 0
                    || ($metadata['attachment_of_message_id'] ?? null) !== $metadata['email_message_id']) {
                    throw new \RuntimeException('Email attachment receipt lacks its parent/slot identity.');
                }
            } else {
                $full = $this->fullPath((string) ($metadata['prefix'] ?? config('kb.sources.path_prefix', '')), $path);
                if (! hash_equals($metadata['email_body_hash'], $this->hashFile($disk, $full))) {
                    throw new \RuntimeException('Email body receipt does not match its prepared commitment.');
                }
            }
        }
        if (! $installation || ! $this->profiles->current($installation)?->enabled) {
            if ($api && ! EmailConnectorRegistry::isAttachment($metadata)) {
                $this->refreshCurrentMetadata($tenant, $project, $metadata);
            }

            return false;
        }
        $identity = $this->identity($metadata);
        $message = EmailTriageMessage::query()->forTenant($tenant)
            ->where('connector_installation_id', $installation->id)->where('identity_hash', $identity)->first();
        if (! $message) {
            // Never guess readiness from a total count or silently ingest an untriaged body.
            throw new \RuntimeException('Email triage manifest was not registered before persistence.');
        }
        $attachment = array_key_exists('attachment_filename', $metadata);
        $prefix = (string) ($metadata['prefix'] ?? config('kb.sources.path_prefix', ''));
        $full = $this->fullPath($prefix, $path);
        $hash = $refusal === null || $api ? $this->hashFile($disk, $full) : null;
        $receiptDispatched = false;
        DB::transaction(function () use ($message, $attachment, $metadata, $path, $disk, $prefix, $full, $hash, $title, $project, $tenant, $refusal, $api, &$receiptDispatched): void {
            $locked = EmailTriageMessage::query()->forTenant($tenant)->lockForUpdate()->findOrFail($message->id);
            if ($api && ($locked->metadata['email_revision_hash'] ?? null) !== $metadata['email_revision_hash']) {
                throw new \RuntimeException('Email receipt belongs to an obsolete revision.');
            }
            if ($attachment) {
                $manifest = $locked->manifest;
                $found = false;
                foreach ($manifest as &$file) {
                    if (! $this->matchesReceipt($file, $metadata, $api) || $file['status'] === 'excluded') {
                        continue;
                    }
                    if (($refusal === null || $api) && $file['hash'] !== $hash) {
                        continue;
                    }
                    if ($file['status'] !== 'pending') {
                        continue;
                    }
                    $file['status'] = $refusal ?? 'saved';
                    $file['reason'] = $refusal;
                    if ($refusal === null) {
                        if (Storage::disk($disk)->size($full) > (int) config('email-triage.extraction_max_bytes', 5242880)) {
                            $file['status'] = 'extraction_size_limit';
                            $file['reason'] = 'extraction_size_limit';
                        } else {
                            $file['snapshot'] = $this->snapshot($tenant, $locked->identity_hash, $disk, $prefix, $full, $hash);
                        }
                    }
                    $found = true;
                    break;
                }
                unset($file);
                if (! $found) {
                    // A repeat receipt is valid only if its content matches an already saved slot.
                    $repeat = collect($manifest)->contains(fn ($f) => $this->matchesReceipt($f, $metadata, $api)
                        && (! $api || $f['hash'] === $hash) && ($refusal === null ? ($f['hash'] === $hash && in_array($f['status'], ['saved', 'extraction_size_limit'], true)) : $f['status'] === $refusal));
                    if (! $repeat) {
                        throw new \RuntimeException('Attachment does not match the durable triage manifest.');
                    }
                }
                if ($api) {
                    $receiptDispatched = collect($manifest)->contains(fn ($file) => $this->matchesReceipt($file, $metadata, true)
                        && ($file['ingest_dispatched'] ?? false) === true);
                }
                $locked->manifest = $manifest;
            } else {
                if ($api) {
                    $metadata = array_replace($metadata, array_intersect_key($locked->metadata, ['previous_document_id' => true, 'access_scope' => true]));
                    $previous = KnowledgeDocument::query()->forTenant($tenant)
                        ->where('project_key', $project)->where('source_path', $path)->where('status', 'active')
                        ->where('metadata->connector', $metadata['connector'])
                        ->where('metadata->installation_id', $metadata['installation_id'])
                        ->where('metadata->email_message_key', $metadata['email_message_key'])->first();
                    if ($previous) {
                        $metadata['previous_document_id'] = $previous->id;
                        $metadata['access_scope'] = $previous->access_scope;
                    }
                }
                if ($api && ! hash_equals($metadata['email_body_hash'], $hash)) {
                    throw new \RuntimeException('Email body receipt does not match its prepared commitment.');
                }
                $locked->forceFill(['project_key' => $project, 'source_path' => $path, 'disk' => $disk,
                    'prefix' => $prefix, 'title' => $title, 'metadata' => $metadata,
                    'original_hash' => $hash,
                    'original_path' => $this->snapshot($tenant, $locked->identity_hash, $disk, $prefix, $full, $hash)]);
            }
            $locked->save();
            if ($api && ! $attachment) {
                $this->refreshCurrentMetadata($tenant, $project, $metadata);
            }
            $this->ready($locked);
        });

        return ! $attachment || $receiptDispatched;
    }

    /** Record dispatch only after the queue accepted it; a failed dispatch remains retryable. */
    public function dispatched(array $metadata, string $tenant): void
    {
        if (! EmailConnectorRegistry::isApi((string) ($metadata['connector'] ?? ''))
            || ! EmailConnectorRegistry::isAttachment($metadata)) {
            return;
        }
        DB::transaction(function () use ($metadata, $tenant): void {
            $message = EmailTriageMessage::query()->forTenant($tenant)
                ->where('connector_installation_id', $metadata['installation_id'])
                ->where('identity_hash', $this->identity($metadata))->lockForUpdate()->first();
            if (! $message || ($message->metadata['email_revision_hash'] ?? null) !== $metadata['email_revision_hash']) {
                return;
            }
            $manifest = $message->manifest;
            foreach ($manifest as &$file) {
                if ($this->matchesReceipt($file, $metadata, true) && $file['status'] !== 'pending' && $file['status'] !== 'excluded') {
                    $file['ingest_dispatched'] = true;
                    break;
                }
            }
            unset($file);
            $message->forceFill(['manifest' => $manifest])->save();
        });
    }

    public function ready(EmailTriageMessage $message): ?EmailTriageEvaluation
    {
        if (! $message->original_path || collect($message->manifest)->contains(fn ($f) => $f['status'] === 'pending')) {
            return null;
        }
        $profile = EmailTriageProfile::query()->forTenant($message->tenant_id)->findOrFail($message->profile_id);
        $inputHash = EmailConnectorRegistry::isApi((string) ($message->metadata['connector'] ?? ''))
            ? $message->metadata['email_revision_hash']
            : hash('sha256', json_encode([$message->original_hash, $message->manifest], JSON_THROW_ON_ERROR));
        $key = hash('sha256', json_encode([$message->tenant_id, $message->id, $inputHash, $profile->id, $profile->model, TriageEngine::RENDERER_VERSION], JSON_THROW_ON_ERROR));
        $evaluation = EmailTriageEvaluation::query()->forTenant($message->tenant_id)->firstOrCreate(['evaluation_key' => $key], [
            'tenant_id' => $message->tenant_id, 'message_id' => $message->id, 'profile_id' => $profile->id,
            'input_hash' => $inputHash,
        ]);
        $message->forceFill(['evaluation_id' => $evaluation->id, 'status' => $evaluation->status])->save();
        if ($evaluation->status !== 'failed' && $evaluation->indexed_at === null) {
            DB::afterCommit(fn () => $this->enqueue($evaluation));
        }

        return $evaluation;
    }

    public function enqueue(EmailTriageEvaluation $evaluation): void
    {
        // Durable row is the outbox. A scheduler recovers a crash between commit and dispatch.
        if ($evaluation->queued_at && $evaluation->queued_at->gt(now()->subMinutes(10))) {
            return;
        }
        $evaluation->forceFill(['queued_at' => now()])->save();
        try {
            AnalyzeEmailJob::dispatch($evaluation->id, $evaluation->tenant_id);
        } catch (\Throwable $exception) {
            $evaluation->forceFill(['queued_at' => null])->save();
            Log::warning('Email triage outbox dispatch deferred', ['tenant_id' => $evaluation->tenant_id,
                'evaluation_id' => $evaluation->id, 'error_code' => class_basename($exception)]);
        }
    }

    public function process(int $id, string $tenant): void
    {
        $evaluation = EmailTriageEvaluation::query()->forTenant($tenant)->findOrFail($id);
        Cache::lock('email-triage:message:'.$tenant.':'.$evaluation->message_id, 240)->block(2, function () use ($id, $tenant): void {
            $evaluation = EmailTriageEvaluation::query()->forTenant($tenant)->findOrFail($id);
            $message = EmailTriageMessage::query()->forTenant($tenant)->findOrFail($evaluation->message_id);
            if ((int) $message->evaluation_id !== $id || $message->status === 'awaiting_files' || $evaluation->indexed_at) {
                return;
            }
            $installation = ConnectorInstallation::query()->where('tenant_id', $tenant)->find($message->connector_installation_id);
            if (! $installation || $installation->status === ConnectorInstallation::STATUS_DISABLED
                || ! $this->profiles->current($installation)?->enabled) {
                return;
            }
            $pausedCampaigns = EmailTriageCampaign::query()->forTenant($tenant)->where('status', 'paused')->select('id');
            if (isset($message->metadata['previous_document_id']) && EmailTriageCampaignItem::query()->forTenant($tenant)
                ->where('message_id', $message->id)->where('status', 'queued')->whereIn('campaign_id', $pausedCampaigns)->exists()) {
                return;
            }
            if ($evaluation->result === null) {
                $profile = EmailTriageProfile::query()->forTenant($tenant)->findOrFail($evaluation->profile_id);
                $evaluation->forceFill(['status' => 'running', 'attempts' => $evaluation->attempts + 1, 'error_code' => null])->save();
                $message->forceFill(['status' => 'running'])->save();
                Log::info('Email triage attempt started', ['tenant_id' => $tenant, 'message_id' => $message->id,
                    'evaluation_id' => $id, 'profile_id' => $profile->id, 'attempt' => $evaluation->attempts]);
                $started = microtime(true);
                try {
                    $prepared = $this->engine->prepare($this->original($message), $message->manifest, $message->disk);
                    $data = $this->engine->analyze($profile, $prepared);
                    $evaluation->forceFill($data + ['status' => 'completed', 'retry_at' => null])->save();
                    Log::info('Email triage completed', ['tenant_id' => $tenant, 'message_id' => $message->id,
                        'evaluation_id' => $id, 'attempt' => $evaluation->attempts, 'model' => $profile->model,
                        'latency_ms' => $data['latency_ms'], 'elapsed_ms' => round((microtime(true) - $started) * 1000),
                        'usage' => $data['usage'], 'partial' => $data['coverage']['partial']]);
                } catch (\Throwable $exception) {
                    $terminal = $evaluation->attempts >= 4;
                    $evaluation->forceFill(['status' => $terminal ? 'failed' : 'waiting',
                        'error_code' => TriageFailure::code($exception), 'retry_at' => now()->addSeconds([30, 120, 600][min(2, $evaluation->attempts - 1)])])->save();
                    $message->forceFill(['status' => $evaluation->status])->save();
                    Log::warning('Email triage attempt failed', ['tenant_id' => $tenant, 'message_id' => $message->id,
                        'evaluation_id' => $id, 'attempt' => $evaluation->attempts, 'error_code' => $evaluation->error_code]);
                    throw $exception;
                }
            }
            // Import replays and a new profile can supersede this decision while it is in flight.
            $message->refresh();
            if ((int) $message->evaluation_id !== $id || (int) $message->profile_id !== (int) $evaluation->profile_id
                || $message->status === 'awaiting_files') {
                return;
            }
            if ($evaluation->index_status === 'queued' && $evaluation->index_queued_at?->gt(now()->subMinutes(10))) {
                return;
            }
            $metadata = $message->metadata;
            $metadata['prefix'] = $message->prefix;
            $metadata['email_triage'] = ['evaluation_id' => $id, 'profile_id' => $evaluation->profile_id,
                'index_generation' => (int) $evaluation->index_generation,
                'original_hash' => $message->original_hash, 'automated' => true,
                'base_search_tags' => (array) data_get($metadata, '_derived.search_tags', [])];
            $baseTags = (array) data_get($metadata, '_derived.search_tags', []);
            data_set($metadata, '_derived.search_tags', array_values(array_unique(array_merge($baseTags, $evaluation->tags ?? []))));
            $message->forceFill(['status' => 'completed'])->save();
            $evaluation->forceFill(['index_status' => 'queued', 'index_queued_at' => now()])->save();
            try {
                IngestDocumentJob::dispatch(projectKey: $message->project_key, relativePath: $message->source_path,
                    disk: $message->disk, title: $message->title, metadata: $metadata, mimeType: 'text/markdown',
                    tenantId: $tenant, runKey: 'email-triage:'.$evaluation->evaluation_key.':index'.(int) $evaluation->index_generation);
            } catch (\Throwable $error) {
                $evaluation->forceFill(['index_status' => 'waiting', 'index_queued_at' => null, 'error_code' => 'ingest_dispatch_deferred'])->save();
                throw $error;
            }
        });
    }

    /** Preserve the logical source path while reading the immutable original plus one derived card. */
    public function sourceForIngest(string $tenant, string $project, string $path, array $metadata): ?string
    {
        $message = $this->assertProjection($tenant, $project, $path, $metadata);
        if ($message === null) {
            return null;
        }
        $evaluation = EmailTriageEvaluation::query()->forTenant($tenant)->findOrFail($message->evaluation_id);

        return $this->original($message).$evaluation->card;
    }

    /** Repeat the provenance check under the final ingest transaction's row lock. */
    public function assertProjection(string $tenant, string $project, string $path, array $metadata, bool $lock = false): ?EmailTriageMessage
    {
        if (EmailConnectorRegistry::isApi((string) ($metadata['connector'] ?? ''))) {
            $this->assertApiSource($tenant, $project, $path, $metadata, $lock);
        }
        $id = data_get($metadata, 'email_triage.evaluation_id');
        if (! $id) {
            return null;
        }
        $evaluation = EmailTriageEvaluation::query()->forTenant($tenant)->findOrFail($id);
        $query = EmailTriageMessage::query()->forTenant($tenant);
        $message = ($lock ? $query->lockForUpdate() : $query)->findOrFail($evaluation->message_id);
        if ($message->project_key !== $project || $message->source_path !== $path
            || (int) $message->evaluation_id !== (int) $id || $evaluation->status !== 'completed'
            || (int) data_get($metadata, 'email_triage.index_generation', 0) !== (int) $evaluation->index_generation
            || data_get($metadata, 'email_triage.original_hash') !== $message->original_hash) {
            throw new \RuntimeException('Triage source provenance is stale or invalid.');
        }

        return $message;
    }

    public function indexed(string $tenant, array $metadata, ?int $documentId, bool $failed = false): void
    {
        $id = data_get($metadata, 'email_triage.evaluation_id');
        if (! $id) {
            return;
        }
        $evaluation = EmailTriageEvaluation::query()->forTenant($tenant)->findOrFail($id);
        if ((int) data_get($metadata, 'email_triage.index_generation', 0) !== (int) $evaluation->index_generation) {
            return;
        }
        if ($failed && $evaluation->indexed_at) {
            return;
        }
        $evaluation->forceFill(['index_status' => $failed ? 'failed' : 'indexed',
            'indexed_document_id' => $documentId, 'indexed_at' => $failed ? null : now()])->save();
        Log::info('Email triage indexing '.($failed ? 'failed' : 'completed'), ['tenant_id' => $tenant,
            'evaluation_id' => $id, 'document_id' => $documentId, 'index_generation' => $evaluation->index_generation]);
        if (! $failed && $documentId) {
            EmailTriageMessage::query()->forTenant($tenant)->whereKey($evaluation->message_id)
                ->where('evaluation_id', $id)->update(['knowledge_document_id' => $documentId]);
        }
    }

    public function retry(EmailTriageMessage $message, ?int $pinnedProfileId = null): void
    {
        abort_unless($message->evaluation_id, 422, 'The message is still waiting for files.');
        $installation = ConnectorInstallation::query()->where('tenant_id', $message->tenant_id)->findOrFail($message->connector_installation_id);
        $current = $pinnedProfileId === null ? $this->profiles->current($installation) : null;
        DB::transaction(function () use ($message, $current): void {
            $message = EmailTriageMessage::query()->forTenant($message->tenant_id)->lockForUpdate()->findOrFail($message->id);
            $evaluation = EmailTriageEvaluation::query()->forTenant($message->tenant_id)->lockForUpdate()->findOrFail($message->evaluation_id);
            abort_unless($evaluation->status === 'failed' || $evaluation->index_status === 'failed', 422, 'The message is not in error.');
            if ($current?->enabled && (int) $current->id !== (int) $evaluation->profile_id) {
                // An unindexed failed email is absent from historical KB queries.
                // Its explicit retry must still be able to use corrected rules.
                $message->forceFill(['profile_id' => $current->id, 'status' => 'waiting'])->save();
                $this->ready($message);

                return;
            }
            // A manual retry starts a fresh Flow generation. Failed persisted
            // runs otherwise consume every queue attempt without executing again.
            $evaluation->forceFill(['status' => $evaluation->result ? 'completed' : 'waiting',
                'attempts' => 0, 'error_code' => null, 'queued_at' => null, 'retry_at' => null,
                'index_generation' => (int) $evaluation->index_generation + 1,
                'index_status' => 'waiting', 'index_queued_at' => null])->save();
            $message->forceFill(['status' => $evaluation->status])->save();
            DB::afterCommit(fn () => $this->enqueue($evaluation));
        });
    }

    public function original(EmailTriageMessage $message): string
    {
        app(ConversionArtifactStore::class)->assertContainedOnDisk($message->disk, $message->original_path);
        $body = Storage::disk($message->disk)->get($message->original_path);
        if (! is_string($body) || $body === '' || hash('sha256', $body) !== $message->original_hash) {
            throw new \RuntimeException('The immutable email original is missing or corrupt.');
        }

        return $body;
    }

    public function identity(array $metadata): string
    {
        if (EmailConnectorRegistry::isApi((string) ($metadata['connector'] ?? ''))) {
            $key = EmailConnectorRegistry::messageKey($metadata);
            if ($key === null) {
                throw new \RuntimeException('An API email needs its scoped message identity.');
            }

            return hash('sha256', $key);
        }
        if (! is_string($metadata['imap_doc_key'] ?? null) || $metadata['imap_doc_key'] === '') {
            throw new \RuntimeException('An email needs its mailbox, UIDVALIDITY and UID identity.');
        }

        return hash('sha256', json_encode([(int) ($metadata['installation_id'] ?? 0), $metadata['imap_doc_key']], JSON_THROW_ON_ERROR));
    }

    /** Mutable provider metadata does not alter the indexed text, tags or decision. */
    private function refreshCurrentMetadata(string $tenant, string $project, array $metadata): void
    {
        $documents = KnowledgeDocument::query()->forTenant($tenant)
            ->where('project_key', $project)->where('status', 'active')
            ->where('metadata->connector', $metadata['connector'])
            ->where('metadata->installation_id', $metadata['installation_id'])
            ->where('metadata->email_message_key', $metadata['email_message_key'])
            ->where('metadata->email_revision_hash', $metadata['email_revision_hash']);
        $common = array_filter($metadata, static fn (string $key): bool => str_starts_with($key, 'email_'), ARRAY_FILTER_USE_KEY);
        unset($common['email_attachment_id'], $common['email_attachment_slot'], $common['email_attachment_hash'], $common['email_triage']);
        $source = str_replace('-', '_', $metadata['connector']);
        foreach ($documents->cursor() as $document) {
            $updated = array_replace($document->metadata, $common);
            if (isset($metadata['converter_hints'][$source])) {
                $updated['converter_hints'][$source] = $metadata['converter_hints'][$source];
            }
            if ($updated !== $document->metadata) {
                $document->forceFill(['metadata' => $updated])->save();
            }
        }
    }

    /** Runs before conversion and again under the final persistence transaction. */
    private function assertApiSource(string $tenant, string $project, string $path, array $metadata, bool $lock): void
    {
        $installation = ConnectorInstallation::query()->where('tenant_id', $tenant)->findOrFail($metadata['installation_id'] ?? 0);
        $this->envelopes->validateMetadata($metadata, $tenant, $installation);
        if ($project !== EmailConnectorRegistry::resolveProject($installation)) {
            throw new \RuntimeException('Email API projection has an invalid project scope.');
        }
        $query = EmailTriageMessage::query()->forTenant($tenant)
            ->where('connector_installation_id', $installation->id)->where('identity_hash', $this->identity($metadata));
        $current = ($lock ? $query->lockForUpdate() : $query)->first();
        $enabled = $this->profiles->current($installation)?->enabled === true;
        $guardManifest = $enabled || (bool) data_get($metadata, 'email_triage.evaluation_id');
        if ($current && $guardManifest && ($current->metadata['email_revision_hash'] ?? null) !== $metadata['email_revision_hash']) {
            throw new \RuntimeException('Email API source revision is obsolete.');
        }
        $attachment = EmailConnectorRegistry::isAttachment($metadata);
        if ($attachment && $current && $guardManifest && ! collect($current->manifest)->contains(fn ($file) => $this->matchesReceipt($file, $metadata, true)
            && ($file['hash'] ?? null) === ($metadata['email_attachment_hash'] ?? null)
            && in_array($file['status'], ['saved', 'extraction_size_limit'], true))) {
            throw new \RuntimeException('Email API attachment projection does not match its receipt.');
        }
        // Triaged bodies read the immutable original; ordinary sources read
        // the live location, whose bytes must still match this queued job.
        if (isset($metadata['disk']) && ($attachment || ! data_get($metadata, 'email_triage.evaluation_id') || ! $enabled)) {
            $expected = $attachment ? ($metadata['email_attachment_hash'] ?? null) : $metadata['email_body_hash'];
            if (! is_string($expected) || ! hash_equals($expected, $this->hashFile(
                (string) $metadata['disk'], $this->fullPath((string) ($metadata['prefix'] ?? ''), $path),
            ))) {
                throw new \RuntimeException('Email API source bytes no longer match the queued receipt.');
            }
        }
    }

    private function matchesReceipt(array $file, array $metadata, bool $api): bool
    {
        if (($file['name'] ?? null) !== ($metadata['attachment_filename'] ?? null)) {
            return false;
        }

        return ! $api || (is_int($metadata['email_attachment_slot'] ?? null)
            && ($file['slot'] ?? null) === $metadata['email_attachment_slot']
            && is_string($metadata['email_attachment_id'] ?? null)
            && ($file['id'] ?? null) === $metadata['email_attachment_id']
            && ($metadata['attachment_of_message_id'] ?? null) === $metadata['email_message_id']);
    }

    public function snapshot(string $tenant, string $identity, string $disk, string $prefix, string $path, string $hash): string
    {
        $snapshot = $this->fullPath($prefix, '.artifacts/email-triage/'.$tenant.'/'.$identity.'/'.$hash.'.source');
        app(ConversionArtifactStore::class)->assertContainedOnDisk($disk, $snapshot);
        $storage = Storage::disk($disk);
        if (! $storage->exists($snapshot) && ! $storage->copy($path, $snapshot)) {
            throw new \RuntimeException('Cannot preserve the immutable triage input.');
        }
        if ($this->hashFile($disk, $snapshot) !== $hash) {
            throw new \RuntimeException('The triage snapshot content does not match its receipt.');
        }

        return $snapshot;
    }

    /** Called inside the ingest transaction, before the old version is archived. */
    public function inheritAccess(KnowledgeDocument $document, array $metadata): void
    {
        $api = EmailConnectorRegistry::isApi((string) ($metadata['connector'] ?? ''));
        $attachment = $api && EmailConnectorRegistry::isAttachment($metadata);
        $parentId = $attachment ? ($metadata['email_parent_document_id'] ?? null) : null;
        $previousId = $metadata['previous_document_id'] ?? null;
        if ((! $previousId && ! $parentId && ! $attachment) || (! $api && ! data_get($metadata, 'email_triage.evaluation_id'))) {
            return;
        }
        $parent = null;
        if ($attachment) {
            $parentQuery = KnowledgeDocument::query()->forTenant($document->tenant_id)
                ->where('metadata->connector', $metadata['connector'])
                ->where('metadata->installation_id', $metadata['installation_id'])
                ->where('metadata->email_message_key', $metadata['email_message_key'])
                ->whereNull('metadata->attachment_filename');
            $parent = (clone $parentQuery)->where('project_key', $document->project_key)->where('status', 'active')
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($parent && $parentId && (int) $parent->id !== (int) $parentId
                && ($parent->metadata['email_revision_hash'] ?? null) !== $metadata['email_revision_hash']) {
                throw new \RuntimeException('Email attachment parent projection is obsolete.');
            }
            if (! $parent && $parentId) {
                $parent = $parentQuery->lockForUpdate()->findOrFail($parentId);
            }
        }
        if ($parent) {
            // The parent's current restriction is authoritative even when its
            // ACL or scope changed after the attachment job was queued.
            $documentMetadata = $document->metadata;
            $documentMetadata['access_scope'] = $parent->access_scope;
            $document->forceFill(['access_scope' => $parent->access_scope, 'metadata' => $documentMetadata])->save();
            $this->copyAccess($document, $parent);
        }
        if (! $previousId) {
            return;
        }
        $query = KnowledgeDocument::query()->forTenant($document->tenant_id)->where('source_path', $document->source_path);
        if ($api) {
            // Explicit project rebinds retain the same parent identity and ACL.
            $query->where('metadata->connector', $metadata['connector'])
                ->where('metadata->installation_id', $metadata['installation_id'])
                ->where('metadata->email_message_key', $metadata['email_message_key']);
        } else {
            $query->where('project_key', $document->project_key);
        }
        $previous = $query->findOrFail($previousId);
        if ($previous->id === $document->id) {
            return;
        }
        $this->copyAccess($document, $previous, $attachment && $parent !== null);
        $document->tags()->syncWithoutDetaching($previous->tags()->pluck('kb_tags.id')->all());
    }

    private function copyAccess(KnowledgeDocument $document, KnowledgeDocument $source, bool $deniesOnly = false): void
    {
        $query = KnowledgeDocumentAcl::query()->forTenant($document->tenant_id)->where('knowledge_document_id', $source->id);
        if ($deniesOnly) {
            $query->where('effect', 'deny');
        }
        foreach ($query->cursor() as $acl) {
            $inherited = KnowledgeDocumentAcl::query()->forTenant($document->tenant_id)->firstOrCreate([
                'knowledge_document_id' => $document->id, 'subject_type' => $acl->subject_type,
                'subject_id' => $acl->subject_id, 'permission' => $acl->permission, 'origin' => $acl->origin,
            ], ['tenant_id' => $document->tenant_id, 'effect' => $acl->effect]);
            // Parent and previous-attachment rules may overlap; a denial from
            // either source cannot be relaxed by inheriting an allow rule.
            if ($acl->effect === 'deny' && $inherited->effect !== 'deny') {
                $inherited->forceFill(['effect' => 'deny'])->save();
            }
        }
    }

    public function hashFile(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Cannot read the triage source.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public function fullPath(string $prefix, string $path): string
    {
        return ($prefix !== '' ? KbPath::normalize($prefix).'/' : '').KbPath::normalize($path);
    }
}
