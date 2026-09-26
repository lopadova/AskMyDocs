<?php

declare(strict_types=1);

namespace App\Routines;

use App\Services\Kb\AutoWiki\WikiMaintainer;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Contracts\Target\ValidationResult;

/**
 * v8.39/W5 (ADR 0033 §3) — thin adapter over the EXISTING
 * {@see WikiMaintainer} core, which `kb:wiki-maintain`'s scheduled cron
 * entry already calls unattended today. No business logic lives here: the
 * adapter only translates between `RoutineTarget`'s contract and
 * `WikiMaintainer::maintain()`'s own signature.
 *
 * `fire()` never throws `MandateExceeded` (`padosoft/laravel-routines-contracts`
 * `Consent\MandateExceeded`) — `WikiMaintainer` has exactly two capabilities
 * (`kb.wiki.compile`, `kb.wiki.lint`), both declared in {@see descriptor()}'s
 * `actionClasses` and both always in scope for what it does. See ADR 0033
 * §7 for why this makes the mandate/pause-and-ask machinery real but
 * currently unreachable — a documented gap, not an oversight.
 */
final class WikiMaintenanceRoutineTarget implements RoutineTarget
{
    /**
     * STABLE FOREVER — {@see RoutineTarget::type()}'s own contract:
     * changing it orphans every existing `routines` row's history.
     */
    public const TYPE = 'askmydocs.wiki_maintenance';

    public function __construct(private readonly WikiMaintainer $maintainer) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor(
            label: 'AskMyDocs — Wiki Maintenance',
            summary: 'Rebuilds the wiki index, lints (optionally fixes), and backfills un-enriched documents for a tenant/project.',
            fields: [
                'tenant' => ['label' => 'Tenant', 'type' => 'string', 'required' => true],
                'project' => ['label' => 'Project (optional)', 'type' => 'string', 'help' => 'Every project in the tenant when omitted.'],
                'fix' => ['label' => 'Apply safe lint auto-fixes', 'type' => 'boolean'],
                'backfill_limit' => ['label' => 'Backfill limit', 'type' => 'integer', 'help' => 'Defaults to config(kb.autowiki.maintenance_backfill_limit).'],
            ],
            actionClasses: ['kb.wiki.compile', 'kb.wiki.lint'],
            // WikiMaintainer::maintain() is a single synchronous call with
            // no resume state of its own — nothing for a routine run to
            // pause and later continue from.
            supportsPause: false,
            // No FinOps metering wired on this path today.
            reportsCost: false,
        );
    }

    public function validate(array $payload): ValidationResult
    {
        $errors = [];

        $tenant = $payload['tenant'] ?? null;
        if (! is_string($tenant) || $tenant === '') {
            $errors['tenant'] = ['Required.'];
        }

        $project = $payload['project'] ?? null;
        if ($project !== null && ! is_string($project)) {
            $errors['project'] = ['Must be a string.'];
        }

        if (isset($payload['fix']) && ! is_bool($payload['fix'])) {
            $errors['fix'] = ['Must be a boolean.'];
        }

        $backfillLimit = $payload['backfill_limit'] ?? null;
        if ($backfillLimit !== null && (! is_int($backfillLimit) || $backfillLimit < 0)) {
            $errors['backfill_limit'] = ['Must be a non-negative integer.'];
        }

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        $result = $this->maintainer->maintain(
            (string) $execution->payload('tenant'),
            $this->stringOrNull($execution->payload('project')),
            (bool) $execution->payload('fix', false),
            $this->intOrNull($execution->payload('backfill_limit')),
        );

        return TargetResult::succeeded(
            message: sprintf(
                'Maintained %d project(s): %d lint issue(s), %d doc(s) backfilled%s.',
                count($result['projects']),
                (int) $result['lint_issues'],
                (int) $result['backfilled'],
                ((int) $result['fixed']) > 0 ? ", {$result['fixed']} dangling pruned" : '',
            ),
            metadata: $result,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
