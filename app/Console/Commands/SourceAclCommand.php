<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UnmappedSourcePrincipal;
use App\Services\Kb\Access\SourceAclTriageService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.33 / ADR 0028 phase 2 — CLI surface (R44) for the source-ACL triage
 * queue, over the same core as the admin API and the MCP read tool.
 */
class SourceAclCommand extends Command
{
    protected $signature = 'kb:source-acl
        {--tenant= : Tenant to report on (defaults to the active tenant)}
        {--project= : Limit to one project}
        {--status=pending : Which entries to list: pending or ignored}
        {--limit=50 : Max entries to list (1-200)}
        {--ignore= : Mark one entry id as ignored and exit}
        {--reopen= : Move one entry id back to pending and exit}';

    protected $description = 'List principals a source named that could not be matched to an internal subject, and record decisions about them.';

    public function handle(SourceAclTriageService $triage, TenantContext $tenants): int
    {
        $tenant = $this->option('tenant');

        if (is_string($tenant) && $tenant !== '') {
            $tenants->set($tenant);
        }

        $decision = $this->applyDecision($triage);

        if ($decision !== null) {
            return $decision;
        }

        $status = (string) $this->option('status');

        if (! in_array($status, UnmappedSourcePrincipal::STATUSES, true)) {
            $this->error('Unknown status: '.$status.'. Expected one of: '.implode(', ', UnmappedSourcePrincipal::STATUSES).'.');

            return self::FAILURE;
        }

        $summary = $triage->summary();

        $this->line('');
        $this->line('  Tenant: <options=bold>'.$tenants->current().'</>');
        $this->line('');
        $this->line('  Documents whose readers the source dictates: <options=bold>'.$summary['documents_restricted'].'</>');
        $this->line('  Unmatched principals awaiting a decision:    <options=bold>'.$summary['pending'].'</>');
        $this->line('  Previously dismissed:                        <options=bold>'.$summary['ignored'].'</>');
        $this->line('');

        $project = $this->option('project');
        $rows = $triage->queue(
            is_string($project) && $project !== '' ? $project : null,
            $status,
            (int) $this->option('limit'),
        );

        if ($rows->isEmpty()) {
            // Not an error, and the common case for a corpus whose connectors
            // do not report permissions at all (R43).
            $this->info('  Nothing '.$status.'.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'project', 'document', 'principal', 'type', 'effect', 'last seen'],
            $rows->map(static fn (UnmappedSourcePrincipal $row): array => [
                $row->id,
                $row->project_key,
                mb_strimwidth((string) ($row->document?->title ?? '—'), 0, 40, '…'),
                mb_strimwidth($row->principal_external_id, 0, 40, '…'),
                $row->principal_type,
                $row->effect,
                $row->last_seen_at?->diffForHumans() ?? '—',
            ])->all(),
        );

        $this->line('');
        $this->line('  Granting access is deliberate and separate: create an ACL row for the');
        $this->line('  internal subject. Use --ignore=<id> to record that none should be.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Handle --ignore / --reopen, or null when neither was given.
     */
    private function applyDecision(SourceAclTriageService $triage): ?int
    {
        foreach ([
            'ignore' => UnmappedSourcePrincipal::STATUS_IGNORED,
            'reopen' => UnmappedSourcePrincipal::STATUS_PENDING,
        ] as $option => $status) {
            $id = $this->option($option);

            if ($id === null || $id === '') {
                continue;
            }

            $row = $triage->setStatus((int) $id, $status);

            if ($row === null) {
                // R14 — a missing id is a failure the caller must see, not a
                // silent success that looks like the decision was recorded.
                $this->error('No triage entry '.$id.' in this tenant.');

                return self::FAILURE;
            }

            $this->info('Entry '.$row->id.' is now '.$row->status.'.');

            return self::SUCCESS;
        }

        return null;
    }
}
