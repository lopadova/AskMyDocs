<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QaInferenceAttempt;
use App\Models\User;
use App\Services\Qa\CampaignLedger;
use Illuminate\Console\Command;

/** Operator-only bootstrap. Manifest files contain no tokens or original messages. */
final class QaCampaignCommand extends Command
{
    protected $signature = 'qa:campaign {action : runtime, create, inspect, export or probe} {--manifest=} {--id=} {--tenant=} {--project=} {--actor=} {--barrier=}';
    protected $description = 'Create or inspect a development-only, budget-reserved QA campaign';

    public function handle(CampaignLedger $ledger): int
    {
        try {
            $ledger->assertDevelopment();
            $tenant = trim((string) $this->option('tenant'));
            $project = trim((string) $this->option('project'));
            $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
            if ($tenant === '' || $project === '' || ! $actorId) throw new \DomainException('qa_explicit_scope_required');
            if ($this->argument('action') === 'probe-reserve') {
                $result = app(\App\Services\Qa\CampaignBudgetProbe::class)->reserveChild($tenant, $project, $actorId,
                    (string) $this->option('id'), (string) $this->option('barrier'));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            } elseif ($this->argument('action') === 'probe') {
                $probe = app(\App\Services\Qa\CampaignBudgetProbe::class);
                $campaign = $probe->create($tenant, $project, User::findOrFail($actorId));
                $this->line(json_encode($probe->exercise($campaign), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                return self::SUCCESS;
            } elseif ($this->argument('action') === 'runtime') {
                $this->line(json_encode(app(\App\Services\Qa\CampaignRuntime::class)->snapshot($tenant, $project), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                return self::SUCCESS;
            } elseif ($this->argument('action') === 'create') {
                $path = (string) $this->option('manifest');
                if (! is_file($path) || filesize($path) > 65536) throw new \DomainException('qa_manifest_file_required');
                $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $campaign = $ledger->create($tenant, $project, User::findOrFail($actorId), $data);
            } elseif (in_array($this->argument('action'), ['inspect', 'export'], true)) {
                $campaign = $ledger->owned((string) $this->option('id'), $tenant, $project, $actorId);
            } else {
                throw new \DomainException('qa_invalid_action');
            }
            $result = $ledger->summary($campaign);
            if ($this->argument('action') === 'export') {
                $result['manifest'] = $campaign->manifest_json;
                $result['attempts_detail'] = QaInferenceAttempt::query()->forTenant($tenant)
                    ->where('campaign_id', $campaign->id)->orderBy('started_at')->orderBy('id')->get()->toArray();
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Validation messages, file paths and model/provider bodies can contain
            // operator data. Report only a bounded closed code/class here.
            $this->error($exception instanceof \DomainException && preg_match('/^qa_[a-z_]+$/D', $exception->getMessage())
                ? $exception->getMessage() : 'qa_campaign_configuration_invalid');
            return self::FAILURE;
        }
    }
}
