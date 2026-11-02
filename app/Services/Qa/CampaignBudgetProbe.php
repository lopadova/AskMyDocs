<?php

declare(strict_types=1);

namespace App\Services\Qa;

use App\Models\AgentRun;
use App\Models\QaCampaign;
use App\Models\QaInferenceAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** An operator-only database exercise: no provider request, no customer run. */
final class CampaignBudgetProbe
{
    public function create(string $tenant, string $project, User $operator): QaCampaign
    {
        Http::preventStrayRequests();
        return DB::transaction(function () use ($tenant, $project, $operator): QaCampaign {
            $campaign = app(CampaignLedger::class)->create($tenant, $project, $operator, [
                'schema_version' => 1, 'budget_usd' => .001, 'max_calls' => 2,
                'corpus_sha256' => hash('sha256', 'simulated-ledger-probe-v1'), 'live_sources' => ['api' => [], 'mcp' => []],
                'allowed_models' => ['qa/reservation-probe' => ['paths' => ['/api/v1/chat/completions'],
                    'input_per_million' => 0, 'output_per_million' => 0, 'output_reserve_tokens' => 1,
                    'response_models' => ['qa/reservation-probe'], 'price_source' => 'https://openrouter.ai/docs',
                    'price_observed_at' => now()->toIso8601String()]],
            ]);
            // No real actor owns this fixture. It cannot be bound through the
            // chat API or enter CampaignScope, even while the probe is running.
            $campaign->forceFill(['actor_id' => 0, 'manifest_json' => [...$campaign->manifest_json,
                'simulation' => 'ledger_reservation_v1', 'probe_operator_id' => $operator->id]])->save();
            return $campaign;
        });
    }

    /** @param array<string,string|false> $processEnvironment Isolated test DB overrides; empty on development. */
    public function exercise(QaCampaign $campaign, array $processEnvironment = []): array
    {
        $this->assertProbe($campaign);
        Http::preventStrayRequests();
        $directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/askmydocs-qa-ledger-'.bin2hex(random_bytes(10));
        if (! mkdir($directory, 0700)) throw new \DomainException('qa_probe_workspace_unavailable');
        $barrier = $directory.'/start';
        $processes = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $root = dirname(__DIR__, 3);
                $process = new Process([PHP_BINARY, $root.'/artisan', 'qa:campaign', 'probe-reserve',
                    '--tenant='.$campaign->tenant_id, '--project='.$campaign->project_key,
                    '--actor='.$campaign->manifest_json['probe_operator_id'], '--id='.$campaign->id, '--barrier='.$barrier],
                    $root, $processEnvironment ?: null);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($barrier.'.*.ready')) < 2 && microtime(true) < $deadline) {
                foreach ($processes as $process) if (! $process->isRunning()) throw new \DomainException('qa_probe_child_unavailable');
                usleep(20000);
            }
            if (count(glob($barrier.'.*.ready')) !== 2) throw new \DomainException('qa_probe_barrier_timeout');
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                if (! $process->isSuccessful()) throw new \DomainException('qa_probe_child_failed');
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $results[] = $result['result'] ?? null;
            }
            sort($results);
            $campaign->refresh();
            if ($results !== ['blocked', 'reserved'] || $campaign->reserved_nanos !== 600000
                || $campaign->spent_nanos !== 0 || $campaign->attempt_count !== 1) {
                throw new \DomainException('qa_probe_shared_budget_violation');
            }
            $attempt = QaInferenceAttempt::query()->forTenant($campaign->tenant_id)->where('campaign_id', $campaign->id)->sole();
            $ledger = app(CampaignLedger::class);
            $ledger->finish($attempt, 0, null, 'qa/reservation-probe', null, null, 'simulation');
            $ledger->finish($attempt, 0, null, 'qa/reservation-probe', null, null, 'simulation');
            $campaign->refresh();
            if ($campaign->reserved_nanos !== 0 || $campaign->spent_nanos !== 0 || $campaign->attempt_count !== 1) {
                throw new \DomainException('qa_probe_duplicate_completion_violation');
            }
            return ['mode' => 'simulated_database_reservations', 'campaign_id' => $campaign->id,
                'driver' => DB::connection()->getDriverName(), 'release' => config('agent.diagnostics.release'),
                'processes' => 2, 'results' => $results, 'spent_usd' => '0.000000000',
                'reserved_after_settlement_usd' => '0.000000000', 'inference_calls' => 0, 'verified_at' => now()->toIso8601String()];
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function reserveChild(string $tenant, string $project, int $operator, string $id, string $barrier): array
    {
        app(CampaignLedger::class)->assertEnabled();
        Http::preventStrayRequests();
        $campaign = QaCampaign::query()->forTenant($tenant)->where('project_key', $project)
            ->where('actor_id', 0)->findOrFail($id);
        $this->assertProbe($campaign);
        if ($campaign->manifest_json['probe_operator_id'] !== $operator
            || ! str_starts_with(realpath(dirname($barrier)) ?: '', (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/askmydocs-qa-ledger-')
            || basename($barrier) !== 'start') throw new \DomainException('qa_probe_scope_mismatch');
        file_put_contents($barrier.'.'.getmypid().'.ready', 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($barrier) && microtime(true) < $deadline) usleep(10000);
        if (! is_file($barrier)) throw new \DomainException('qa_probe_barrier_timeout');
        $run = new AgentRun(['tenant_id' => $tenant, 'project_key' => $project, 'user_id' => 0,
            'run_id' => (string) Str::uuid(), 'input_json' => ['qa' => ['round' => 'probe', 'case_id' => 'LEDGER-PROBE', 'turn' => 0]]]);
        try {
            app(CampaignLedger::class)->reserve($campaign, $run, (string) Str::uuid(), ['path' => '/api/v1/chat/completions',
                'requested_model' => 'qa/reservation-probe', 'request_sha256' => hash('sha256', 'no-inference-probe')], 600000);
            return ['result' => 'reserved'];
        } catch (\DomainException $exception) {
            if ($exception->getMessage() !== 'qa_budget_exhausted') throw $exception;
            return ['result' => 'blocked'];
        }
    }

    private function assertProbe(QaCampaign $campaign): void
    {
        app(CampaignLedger::class)->assertEnabled();
        if ((int) $campaign->actor_id !== 0 || data_get($campaign->manifest_json, 'simulation') !== 'ledger_reservation_v1'
            || $campaign->budget_nanos !== 1000000 || $campaign->manifest_json['max_calls'] !== 2) {
            throw new \DomainException('qa_probe_scope_mismatch');
        }
    }
}
