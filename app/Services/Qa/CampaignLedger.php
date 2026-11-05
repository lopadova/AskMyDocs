<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Models\AgentRun;
use App\Models\QaCampaign;
use App\Models\QaInferenceAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A campaign row lock serializes reservations across PHP processes and containers. */
final class CampaignLedger
{
    public function assertEnabled(): void
    {
        $this->assertDevelopment();
        if (! config('qa.campaigns_enabled', false)) throw new \DomainException('qa_campaigns_disabled');
    }

    public function assertDevelopment(): void
    {
        if (! app()->environment('local', 'testing') && config('qa.deployment_tier') !== 'development') {
            throw new \DomainException('qa_campaigns_disabled');
        }
    }

    public function create(string $tenant, string $project, User $actor, array $manifest): QaCampaign
    {
        $this->assertEnabled();
        if (! $actor->is_active || ! $actor->hasAnyRole(['admin', 'super-admin'])
            || ! \App\Models\ProjectMembership::query()->forTenant($tenant)->where('user_id', $actor->id)->exists()
            || (! $actor->canReadAllProjects() && ! in_array($project, $actor->allowedProjects(), true))) {
            throw new \DomainException('qa_campaign_actor_not_authorized');
        }
        $manifest = CampaignManifest::validate($manifest);
        $release = config('agent.diagnostics.release');
        if (! is_string($release) || ! preg_match('/^[a-f0-9]{7,40}$/D', $release)) {
            throw new \DomainException('qa_release_required');
        }
        $manifest['created_release'] = $release;
        $manifest['configuration'] = app(CampaignRuntime::class)->snapshot($tenant, $project);
        return QaCampaign::forceCreate(['id' => (string) Str::uuid(), 'tenant_id' => $tenant,
            'project_key' => $project, 'actor_id' => $actor->id, 'status' => 'active',
            'budget_nanos' => CampaignManifest::nanos($manifest['budget_usd']), 'manifest_json' => $manifest]);
    }

    public function owned(string $id, string $tenant, string $project, int $actor): QaCampaign
    {
        $this->assertDevelopment();
        return QaCampaign::query()->forTenant($tenant)->where('project_key', $project)->where('actor_id', $actor)->findOrFail($id);
    }

    public function reserve(QaCampaign $campaign, AgentRun $run, string $scope, array $request, int $amount): QaInferenceAttempt
    {
        if ($amount < 1) throw new \DomainException('qa_invalid_reservation');
        $result = DB::transaction(function () use ($campaign, $run, $scope, $request, $amount): QaInferenceAttempt|string {
            $locked = $this->lockedCampaign($campaign->tenant_id, $campaign->id);
            if ($locked->status !== 'active') return $locked->stop_reason ?? 'qa_campaign_stopped';
            // A dead process never releases its reservation by assuming a zero bill.
            if (QaInferenceAttempt::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)
                ->where('status', 'pending')->where('started_at', '<', now()->subSeconds(max(180, (int) config('agent.job_timeout_seconds', 120) + 30)))->exists()) {
                $this->stop($locked, 'qa_stale_reservation');
                return 'qa_stale_reservation';
            }
            if ($locked->spent_nanos + $locked->reserved_nanos + $amount > $locked->budget_nanos
                || $locked->attempt_count >= $locked->manifest_json['max_calls']) {
                $this->stop($locked, 'qa_budget_exhausted');
                return 'qa_budget_exhausted';
            }
            $locked->increment('reserved_nanos', $amount);
            $locked->increment('attempt_count');
            return QaInferenceAttempt::forceCreate(['id' => (string) Str::uuid(), 'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id, 'run_uuid' => $run->run_id, 'scope_id' => $scope,
                'round' => data_get($run->input_json, 'qa.round'), 'case_id' => data_get($run->input_json, 'qa.case_id'),
                'turn' => data_get($run->input_json, 'qa.turn'), ...$request,
                'reserved_nanos' => $amount, 'status' => 'pending', 'started_at' => now()]);
        }, 3);
        if (is_string($result)) throw new \DomainException($result);
        return $result;
    }

    public function finish(QaInferenceAttempt $attempt, ?int $cost, ?int $httpStatus, ?string $model, ?string $error,
        ?string $generation = null, ?string $costSource = null, ?int $durationMs = null): void
    {
        DB::transaction(function () use ($attempt, $cost, $httpStatus, $model, $error, $generation, $costSource, $durationMs): void {
            $campaign = $this->lockedCampaign($attempt->tenant_id, $attempt->campaign_id);
            $locked = QaInferenceAttempt::query()->forTenant($attempt->tenant_id)->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'pending') return;
            $spec = $campaign->manifest_json['allowed_models'][$locked->requested_model];
            if ($model === null || ! in_array($model, $spec['response_models'], true)) $error ??= 'qa_response_model_mismatch';
            if ($cost !== null && $cost > $locked->reserved_nanos) $error ??= 'qa_reservation_exceeded';
            $locked->forceFill(['cost_nanos' => $cost, 'http_status' => $httpStatus, 'actual_model' => $model,
                'generation_id' => $generation, 'cost_source' => $cost === null ? 'unknown' : $costSource,
                'duration_ms' => $durationMs,
                'status' => $cost === null ? 'unknown' : 'recorded', 'error_code' => $error, 'completed_at' => now()])->save();
            // Unknown charges retain their full reservation and stop the campaign.
            if ($cost !== null) {
                $campaign->reserved_nanos -= $locked->reserved_nanos;
                $campaign->spent_nanos += $cost;
            }
            if ($cost === null || $error !== null) {
                $campaign->status = 'stopped';
                $campaign->stop_reason ??= $error ?? 'qa_unknown_billing';
            }
            $campaign->save();
        }, 3);
    }

    public function interruptScope(string $tenant, string $campaign, string $scope): void
    {
        foreach (QaInferenceAttempt::query()->forTenant($tenant)->where('campaign_id', $campaign)
            ->where('scope_id', $scope)->where('status', 'pending')->get() as $attempt) {
            $this->finish($attempt, null, null, null, 'qa_interrupted_request');
        }
    }

    public function stop(QaCampaign $campaign, string $reason): void
    {
        // Preserve the first reason even if another process stopped this row.
        QaCampaign::query()->forTenant($campaign->tenant_id)->whereKey($campaign->id)
            ->whereNull('stop_reason')->update(['status' => 'stopped', 'stop_reason' => $reason]);
    }

    public function summary(QaCampaign $campaign): array
    {
        $campaign->refresh();
        return ['id' => $campaign->id, 'status' => $campaign->status, 'stop_reason' => $campaign->stop_reason,
            'budget_usd' => CampaignManifest::usd($campaign->budget_nanos),
            'spent_usd' => CampaignManifest::usd($campaign->spent_nanos),
            'reserved_usd' => CampaignManifest::usd($campaign->reserved_nanos), 'attempts' => $campaign->attempt_count,
            'unknown_attempts' => QaInferenceAttempt::query()->forTenant($campaign->tenant_id)
                ->where('campaign_id', $campaign->id)->where('status', 'unknown')->count(),
            'corpus_sha256' => $campaign->manifest_json['corpus_sha256'],
            'created_release' => $campaign->manifest_json['created_release']];
    }

    private function lockedCampaign(string $tenant, string $id): QaCampaign
    {
        // SQLite has no SELECT FOR UPDATE. Acquire its write lock before reading
        // the balance; busy_timeout can then wait without a read-to-write race.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('qa_campaigns')->where('tenant_id', $tenant)->where('id', $id)->update(['id' => DB::raw('id')]);
        }
        return QaCampaign::query()->forTenant($tenant)->lockForUpdate()->findOrFail($id);
    }
}
