<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Models\AgentRun;
use App\Models\QaCampaign;
use App\Models\QaInferenceAttempt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reconcile one known provider naming discrepancy; never renew a spending budget. */
final class CampaignReconciliation
{
    public function embeddingIdentity(string $tenant, string $project, User $actor, string $id): QaCampaign
    {
        $ledger = app(CampaignLedger::class);
        $ledger->assertEnabled();
        $ledger->assertActor($tenant, $project, $actor);
        $release = config('agent.diagnostics.release');
        if (! is_string($release) || ! preg_match('/^[a-f0-9]{7,40}$/D', $release)) throw new \DomainException('qa_release_required');
        $campaign = $ledger->owned($id, $tenant, $project, (int) $actor->id);
        $attempts = $this->eligibleAttempts($campaign);
        $proofs = [];
        // Provider reads occur outside the DB lock. Recheck all state under the
        // lock afterwards; no inference or monetary settlement is performed.
        foreach ($attempts as $attempt) {
            $proof = VerifiedEmbeddingIdentity::verify($attempt, $attempt->actual_model, $attempt->generation_id, $attempt->cost_nanos);
            if ($proof === null) throw new \DomainException('qa_identity_not_verified');
            $proofs[$attempt->id] = $proof;
        }
        return DB::transaction(function () use ($campaign, $proofs, $actor, $ledger): QaCampaign {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('qa_campaigns')->where('tenant_id', $campaign->tenant_id)->where('id', $campaign->id)
                    ->update(['id' => DB::raw('id')]);
            }
            $locked = QaCampaign::query()->forTenant($campaign->tenant_id)->lockForUpdate()->findOrFail($campaign->id);
            $ledger->assertEnabled();
            $ledger->assertActor($locked->tenant_id, $locked->project_key, $actor->fresh());
            $attempts = $this->eligibleAttempts($locked);
            if ($attempts->count() !== count($proofs)) throw new \DomainException('qa_reconciliation_state_changed');
            foreach ($attempts as $attempt) {
                $proof = $proofs[$attempt->id] ?? null;
                if ($proof === null || ! $proof->matches($attempt, $attempt->actual_model, $attempt->generation_id, $attempt->cost_nanos)) {
                    throw new \DomainException('qa_reconciliation_state_changed');
                }
                // Keep the original raw model, stop error, cost and timestamp.
                $attempt->forceFill(['identity_json' => $proof->metadata])->save();
            }
            $manifest = $locked->manifest_json;
            $manifest['reconciliations'][] = [
                'kind' => 'verified_embedding_identity', 'previous_status' => $locked->status,
                'previous_stop_reason' => $locked->stop_reason, 'actor_id' => $actor->id,
                'release' => config('agent.diagnostics.release'), 'recorded_at' => now()->toIso8601String(),
                'attempt_ids' => $attempts->pluck('id')->all(),
                'budget_nanos' => $locked->budget_nanos, 'spent_nanos' => $locked->spent_nanos,
                'reserved_nanos' => $locked->reserved_nanos, 'attempt_count' => $locked->attempt_count,
            ];
            $locked->forceFill(['manifest_json' => $manifest, 'status' => 'active', 'stop_reason' => null])->save();
            return $locked;
        }, 3);
    }

    /** @return Collection<int,QaInferenceAttempt> */
    private function eligibleAttempts(QaCampaign $campaign): Collection
    {
        if ($campaign->status !== 'stopped' || $campaign->stop_reason !== 'qa_response_model_mismatch'
            || $campaign->reserved_nanos !== 0 || $campaign->spent_nanos >= $campaign->budget_nanos
            || $campaign->attempt_count >= $campaign->manifest_json['max_calls']
            || count($campaign->manifest_json['reconciliations'] ?? []) >= 100) {
            throw new \DomainException('qa_campaign_not_reconcilable');
        }
        if (AgentRun::query()->forTenant($campaign->tenant_id)->where('project_key', $campaign->project_key)
            ->where('input_json->qa->campaign_id', $campaign->id)->whereNotIn('status', ['completed', 'failed', 'cancelled'])->exists()) {
            throw new \DomainException('qa_campaign_has_open_runs');
        }
        $all = QaInferenceAttempt::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)->get();
        if ($all->count() !== $campaign->attempt_count || $all->sum('cost_nanos') !== $campaign->spent_nanos
            || $all->contains(fn ($attempt) => $attempt->status !== 'recorded' || $attempt->cost_nanos === null
                || $attempt->cost_nanos > $attempt->reserved_nanos || $attempt->http_status !== 200
                || ! in_array($attempt->error_code, [null, 'qa_response_model_mismatch'], true))) {
            throw new \DomainException('qa_campaign_has_unreconciled_charges');
        }
        $mismatches = $all->filter(fn ($attempt) => $attempt->error_code === 'qa_response_model_mismatch' && $attempt->identity_json === null);
        if ($mismatches->isEmpty()) throw new \DomainException('qa_campaign_not_reconcilable');
        return $mismatches;
    }
}
