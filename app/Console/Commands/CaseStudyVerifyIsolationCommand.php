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
        {--project=* : Limit to specific case-study project_key(s) (default: all three)}
        {--strict : Also fail when a cross-company question is answered instead of refused (README refusal ideal), even if nothing leaked}';

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

        $strict = (bool) $this->option('strict');

        $this->info("Documentation isolation — tenant '{$tenant}', " . count($cases) . ' case(s) across ' . count($projects) . ' project(s).');
        $this->line('FAIL = a foreign document leaked (isolation breach). WARN = a cross-company question was answered from the company\'s OWN docs instead of refused (no leak; --strict to fail on it).');
        $this->line('');

        $rows = [];
        $leaks = 0;     // hard isolation breaches
        $warnings = 0;  // refusal ideal missed, but no leak

        foreach ($cases as $case) {
            $result = $retrieval->retrieve($case['question'], $case['project'], null);
            $refused = $retrieval->shouldRefuse($result);
            $citations = $retrieval->buildCitations($result);

            $verdict = IsolationMatrix::evaluate($case, $result, $citations, $refused);
            $hard = $verdict['hard'];
            $soft = $verdict['soft'];

            if ($hard !== []) {
                $leaks++;
                $status = '<error>FAIL</error>';
                $detail = implode('; ', $hard);
            } elseif ($soft !== []) {
                $warnings++;
                $status = $strict ? '<error>FAIL</error>' : '<comment>WARN</comment>';
                $detail = implode('; ', $soft);
            } else {
                $status = '<info>PASS</info>';
                $detail = '';
            }

            $rows[] = [$case['id'], $case['project'], $case['kind'], $status, $detail];
        }

        $this->table(['Case', 'Project', 'Kind', 'Result', 'Detail'], $rows);
        $this->line('');

        return $this->summarize(count($cases), $leaks, $warnings, $strict);
    }

    private function summarize(int $total, int $leaks, int $warnings, bool $strict): int
    {
        $passed = $total - $leaks - $warnings;

        if ($leaks > 0) {
            $this->error("{$leaks}/{$total} case(s) LEAK across companies (isolation broken). {$passed} clean, {$warnings} refusal warning(s).");
            return self::FAILURE;
        }

        if ($warnings === 0) {
            $this->info("All {$total} case(s) passed — isolation holds, no documents cross company boundaries.");
            return self::SUCCESS;
        }

        // No leaks: isolation holds. The warnings are refusal-calibration only.
        if ($strict) {
            $this->error("Isolation holds (0 leaks across {$total} case(s)), but {$warnings} cross-company question(s) were answered instead of refused (--strict).");
            return self::FAILURE;
        }

        $this->info("Isolation holds: 0 leaks across {$total} case(s). {$warnings} refusal-calibration warning(s) — the company answered an off-topic question from its OWN docs without leaking. Raise kb.refusal.min_chunk_similarity, or run with --strict, to enforce the README refusal ideal.");
        return self::SUCCESS;
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
