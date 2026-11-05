<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningDisabled;
use App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use RuntimeException;

/**
 * W7 — the PHP surface of the expansion learning loop (R44). Thin adapter over
 * {@see ExpansionLearningService}:
 *
 *   kb:expansion-learn status   [--tenant=] [--project=]
 *   kb:expansion-learn analyze  --project= [--tenant=] [--no-gate]
 *   kb:expansion-learn apply    --id=      [--tenant=]
 *   kb:expansion-learn reject   --id=      [--tenant=]
 *   kb:expansion-learn train    [--project=] [--backend=export|openai] [--base-model=] [--tenant=] --yes
 *   kb:expansion-learn refresh  --id=      [--tenant=]
 *   kb:expansion-learn promote  --id=      [--tenant=] --yes
 *   kb:expansion-learn retire   --id=      [--tenant=]
 *   kb:expansion-learn prune    [--days=]
 *   kb:expansion-learn nightly  (scheduled: prune + analyse every eligible pair + refresh jobs)
 *
 * Every action except `status` and `nightly` requires KB_EXPANSION_LEARNING_ENABLED;
 * `nightly` is a silent no-op while it is off (so the scheduler slot is harmless).
 */
final class KbExpansionLearnCommand extends Command
{
    protected $signature = 'kb:expansion-learn
        {action : status|analyze|apply|reject|train|refresh|promote|retire|prune|nightly}
        {--tenant=default : Tenant to operate on}
        {--project= : Project key}
        {--id= : Tuning id (apply/reject) or model run id (refresh/promote/retire)}
        {--backend=export : Training backend: export (JSONL on the private disk) or openai (fine-tuning API)}
        {--base-model= : Base model for the openai backend (code-owned allow-list)}
        {--days= : Retention override for prune}
        {--no-gate : analyze without running the benchmark gate (auto-apply is then impossible)}
        {--yes : Confirm a train / promote}';

    protected $description = 'Operate the query-expansion learning loop (signals → proposals → gate → apply; training and human promotion).';

    public function handle(ExpansionLearningService $expansionLearningService, TenantContext $tenantContext): int
    {
        $action = (string) $this->argument('action');
        $tenant = trim((string) $this->option('tenant'));
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            return $this->dispatch($action, $tenant, $expansionLearningService, $tenantContext);
        } catch (ExpansionLearningDisabled $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InvalidArgumentException|ModelNotFoundException|RuntimeException $e) {
            $this->error($e instanceof ModelNotFoundException ? 'No such tuning / model run in this tenant.' : $e->getMessage());

            return self::FAILURE;
        } finally {
            $tenantContext->set($previous);
        }
    }

    private function dispatch(string $action, string $tenant, ExpansionLearningService $service, TenantContext $tenantContext): int
    {
        $project = trim((string) $this->option('project'));
        $actor = 'cli:'.(get_current_user() ?: 'operator');

        return match ($action) {
            'status' => $this->emit($service->status($tenant, $project === '' ? null : $project)),
            'analyze' => $this->analyze($service, $tenant, $project, $actor),
            'apply' => $this->emit($service->apply($tenant, $this->id(), $actor)),
            'reject' => $this->emit($service->reject($tenant, $this->id(), $actor)),
            'train' => $this->confirmed('train') ? $this->emit($service->train($tenant, $project === '' ? null : $project, (string) $this->option('backend'), $this->option('base-model') ?: null, $actor)) : self::FAILURE,
            'refresh' => $this->emit($service->refresh($tenant, $this->id())),
            'promote' => $this->confirmed('promote') ? $this->emit($service->promote($tenant, $this->id(), $actor)) : self::FAILURE,
            'retire' => $this->emit($service->retire($tenant, $this->id(), $actor)),
            'prune' => $this->emit(['deleted' => $service->prune($this->option('days') === null ? null : (int) $this->option('days'))]),
            'nightly' => $this->nightly($service, $tenantContext),
            default => throw new InvalidArgumentException("Unknown action [{$action}]."),
        };
    }

    private function analyze(ExpansionLearningService $service, string $tenant, string $project, string $actor): int
    {
        if ($project === '') {
            throw new InvalidArgumentException('analyze needs --project=.');
        }
        $proposal = $service->analyze($tenant, $project, $actor, ! $this->option('no-gate'));
        if ($proposal === null) {
            $this->warn('Not enough expansion events in the window to propose anything (kb.expansion_learning.min_events).');

            return self::SUCCESS;
        }

        return $this->emit($proposal);
    }

    private function nightly(ExpansionLearningService $service, TenantContext $tenantContext): int
    {
        if (! $service->enabled()) {
            $this->line('Expansion learning is disabled; nothing to do.');

            return self::SUCCESS;
        }
        $deleted = $service->prune();
        $proposals = 0;
        foreach ($service->analysablePairs() as $pair) {
            $tenantContext->set($pair['tenant_id']);
            $proposals += $service->analyze($pair['tenant_id'], $pair['project_key'], 'system:nightly') === null ? 0 : 1;
        }
        $this->info(sprintf('pruned %d event(s), recorded %d proposal(s)', $deleted, $proposals));

        return self::SUCCESS;
    }

    private function id(): int
    {
        $id = $this->option('id');
        if (! is_numeric($id) || (int) $id < 1) {
            throw new InvalidArgumentException('--id must be a positive integer.');
        }

        return (int) $id;
    }

    private function confirmed(string $what): bool
    {
        if ($this->option('yes')) {
            return true;
        }
        $this->error("Refusing to {$what} without --yes (it sends masked data to a provider or changes the live expansion model).");

        return false;
    }

    /** @param  array<string,mixed>  $payload */
    private function emit(array $payload): int
    {
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
