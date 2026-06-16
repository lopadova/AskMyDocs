<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Chat\ChatRetrievalService;
use App\Support\CaseStudy\IsolationMatrix;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Run the documentation-isolation matrix against an already-ingested live
 * database and print a PASS/FAIL table — the operator-facing, staging-friendly
 * form of `docs/case-studies/README.md` §6, sharing the SAME
 * {@see IsolationMatrix} as the automated {@see \Tests\Live\Rag\LiveRagIsolationTest}
 * so the two can never drift.
 *
 * Prerequisite: the three case-study datasets are ingested under the target
 * tenant (run `docs/case-studies/ingest.sh`). Each case is verified in-process
 * via {@see ChatRetrievalService} (real retrieval + refusal + citations) — NO
 * chat LLM is invoked, so the verdict is deterministic and the only external
 * cost is the per-question embedding. Exits non-zero on any failure, so it
 * doubles as a manual / CI gate on staging.
 *
 * This is a dev/ops diagnostic with NO HTTP or MCP surface by design (R44
 * single-surface exception): it asserts an invariant of the existing chat
 * retrieval capability rather than exposing a new product capability.
 */
final class CaseStudyVerifyIsolationCommand extends Command
{
    protected $signature = 'case-study:verify-isolation
        {--tenant=default : Tenant to scope the verification to (datasets must be ingested here)}
        {--project=* : Limit to specific case-study project_key(s) (default: all three)}';

    protected $description = 'Verify per-company documentation isolation (README §6 matrix) against the live KB.';

    public function handle(ChatRetrievalService $retrieval, TenantContext $tenants): int
    {
        $projects = $this->resolveProjects();
        if ($projects === null) {
            return self::FAILURE;
        }

        $tenant = (string) $this->option('tenant');
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            return $this->verify($retrieval, $tenant, $projects);
        } finally {
            $tenants->set($previous);
        }
    }

    /**
     * @param  list<string>  $projects
     */
    private function verify(ChatRetrievalService $retrieval, string $tenant, array $projects): int
    {
        $cases = array_values(array_filter(
            IsolationMatrix::cases(),
            static fn (array $case): bool => in_array($case['project'], $projects, true),
        ));

        $this->info("Documentation isolation — tenant '{$tenant}', " . count($cases) . ' case(s) across ' . count($projects) . ' project(s).');
        $this->line('');

        $rows = [];
        $failed = 0;

        foreach ($cases as $case) {
            $result = $retrieval->retrieve($case['question'], $case['project'], null);
            $refused = $retrieval->shouldRefuse($result);
            $citations = $retrieval->buildCitations($result);

            $failures = IsolationMatrix::evaluate($case, $result, $citations, $refused);
            $passed = $failures === [];
            $failed += $passed ? 0 : 1;

            $rows[] = [
                $case['id'],
                $case['project'],
                $case['kind'],
                $passed ? '<info>PASS</info>' : '<error>FAIL</error>',
                $passed ? '' : implode('; ', $failures),
            ];
        }

        $this->table(['Case', 'Project', 'Kind', 'Result', 'Detail'], $rows);
        $this->line('');

        $total = count($cases);
        $passedCount = $total - $failed;
        if ($failed === 0) {
            $this->info("All {$total} isolation case(s) passed — documents do not cross company boundaries.");
            return self::SUCCESS;
        }

        $this->error("{$failed}/{$total} isolation case(s) FAILED ({$passedCount} passed). Documents are leaking across companies.");
        return self::FAILURE;
    }

    /**
     * Resolve the requested project set, validating any --project values
     * against the known case-study keys. Returns null on an invalid request
     * (the caller then exits FAILURE).
     *
     * @return list<string>|null
     */
    private function resolveProjects(): ?array
    {
        /** @var list<string> $requested */
        $requested = (array) $this->option('project');
        if ($requested === []) {
            return IsolationMatrix::PROJECTS;
        }

        $unknown = array_diff($requested, IsolationMatrix::PROJECTS);
        if ($unknown !== []) {
            $this->error('Unknown case-study project(s): ' . implode(', ', $unknown)
                . '. Valid keys: ' . implode(', ', IsolationMatrix::PROJECTS) . '.');
            return null;
        }

        return array_values($requested);
    }
}
