<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Retrieval\RetrievalExplainService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.41/W1 (ADR 0035 §D1) — operator CLI over the retrieval pipeline, with an
 * optional per-result `--explain` trace. Read-only. Thin adapter over
 * {@see RetrievalExplainService} (R44 — one core, three surfaces).
 *
 *   php artisan kb:search "come si annulla un ordine" --project=ops --explain
 *   php artisan kb:search "refund policy" --json
 *
 * The CLI is an operator surface and works regardless of
 * `KB_RETRIEVAL_EXPLAIN_ENABLED` (that flag gates only the HTTP + MCP
 * surfaces). Without `--explain` the trace block is omitted from the output.
 */
final class KbSearchCommand extends Command
{
    protected $signature = 'kb:search
        {query : Natural-language query}
        {--project= : Restrict to one project_key}
        {--tenant=default : Tenant that owns the project}
        {--limit=8 : Number of chunks to return}
        {--intent= : Disambiguation hint (e.g. "web page load times" for the query "performance")}
        {--explain : Include the per-result ranking trace}
        {--investigation : Trace the recursive investigation the chat runs (model-written round queries + outcome)}
        {--min-score= : Drop results scoring below this value (rerank score, else vector score)}
        {--all : Return as many results as the explain cap allows (KB_RETRIEVAL_EXPLAIN_MAX_LIMIT)}
        {--line-numbers : Add the line range of each chunk in the document view (for kb:show --from)}
        {--files : Print one matching document per line (ref, score, path) — for piping}
        {--csv : Emit CSV (ref, score, source_path, heading_path, chunk_id)}
        {--md : Emit a Markdown list}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Run a retrieval query (optionally with a per-result ranking explanation).';

    public function handle(RetrievalExplainService $retrievalExplainService, TenantContext $tenantContext): int
    {
        $query = trim((string) $this->argument('query'));
        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));

        if ($query === '') {
            $this->error('A non-empty query is required.');

            return self::FAILURE;
        }
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        $minScore = $this->option('min-score');
        if ($minScore !== null && $minScore !== '' && ! is_numeric($minScore)) {
            $this->error('--min-score must be a number.');

            return self::FAILURE;
        }
        $formats = array_keys(array_filter(['json' => $this->option('json'), 'files' => $this->option('files'), 'csv' => $this->option('csv'), 'md' => $this->option('md')]));
        if (count($formats) > 1) {
            $this->error('Choose at most one output format: --json, --files, --csv or --md.');

            return self::FAILURE;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            $payload = $retrievalExplainService->explain(
                $query,
                $project === '' ? null : $project,
                $this->option('all') ? $retrievalExplainService->maxLimit() : max(1, (int) $this->option('limit')),
                intent: trim((string) $this->option('intent')) !== '' ? (string) $this->option('intent') : null,
                investigation: (bool) $this->option('investigation'),
                minScore: $minScore === null || $minScore === '' ? null : (float) $minScore,
                lineNumbers: (bool) $this->option('line-numbers'),
            );
        } finally {
            $tenantContext->set($previous);
        }

        if (! $this->option('explain')) {
            $payload['results'] = array_map(static function (array $r): array {
                unset($r['explain']);

                return $r;
            }, $payload['results']);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($this->option('files')) {
            $this->renderFiles($payload['results']);

            return self::SUCCESS;
        }
        if ($this->option('csv')) {
            $this->renderCsv($payload['results']);

            return self::SUCCESS;
        }
        if ($this->option('md')) {
            $this->renderMarkdown($payload);

            return self::SUCCESS;
        }

        $this->render($payload, (bool) $this->option('explain'));

        return self::SUCCESS;
    }

    /** @param  list<array<string,mixed>>  $results  one line per DOCUMENT (best chunk wins), qmd `--files` style */
    private function renderFiles(array $results): void
    {
        $seen = [];
        foreach ($results as $r) {
            $path = (string) ($r['document']['source_path'] ?? '');
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $this->line(sprintf('%s,%s,%s', (string) ($r['document']['ref'] ?? ''), $this->score($r), $path));
        }
    }

    /** @param  list<array<string,mixed>>  $results */
    private function renderCsv(array $results): void
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Cannot open a temporary stream for CSV output.');
        }
        fputcsv($out, ['ref', 'score', 'source_path', 'heading_path', 'chunk_id', 'start_line', 'end_line'], escape: '');
        foreach ($results as $r) {
            fputcsv($out, array_map([$this, 'csvCell'], [
                (string) ($r['document']['ref'] ?? ''),
                $this->score($r),
                (string) ($r['document']['source_path'] ?? ''),
                (string) ($r['heading_path'] ?? ''),
                (string) ($r['chunk_id'] ?? ''),
                (string) ($r['lines']['start_line'] ?? ''),
                (string) ($r['lines']['end_line'] ?? ''),
            ]), escape: '');
        }
        rewind($out);
        $this->output->write((string) stream_get_contents($out));
        fclose($out);
    }

    /** SEC-CSV — neutralise a formula-leading cell (=, +, -, @, tab, CR) so a spreadsheet never evaluates it. */
    private function csvCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }

    /** @param  array<string,mixed>  $payload */
    private function renderMarkdown(array $payload): void
    {
        $this->line('## Results for: '.str_replace(["\r", "\n"], ' ', (string) $payload['query']));
        $this->line('');
        foreach ($payload['results'] as $i => $r) {
            $lines = isset($r['lines']['start_line']) ? sprintf(' (lines %d-%d)', $r['lines']['start_line'], $r['lines']['end_line']) : '';
            $this->line(sprintf('%d. `%s` %s — score %s%s', $i + 1, (string) ($r['document']['source_path'] ?? '?'), (string) ($r['document']['ref'] ?? ''), $this->score($r), $lines));
            if (($r['heading_path'] ?? '') !== '') {
                $this->line('   - '.$r['heading_path']);
            }
        }
    }

    /** @param  array<string,mixed>  $r */
    private function score(array $r): string
    {
        return (string) round((float) ($r['rerank_score'] ?? $r['vector_score'] ?? 0.0), 4);
    }

    /** @param  array<string,mixed>  $payload */
    private function render(array $payload, bool $explain): void
    {
        $this->line(sprintf(
            '%d result(s) — fusion=%s hybrid=%s fts_syntax=%s',
            count($payload['results']),
            $payload['meta']['fusion_method'] ?? '?',
            $payload['meta']['hybrid_search'] ? 'on' : 'off',
            $payload['meta']['fts_syntax'],
        ));

        $investigation = $payload['meta']['investigation'] ?? null;
        if (is_array($investigation)) {
            $this->line(sprintf(
                'investigation status=%s stop=%s selected=%s',
                (string) ($investigation['status'] ?? '?'),
                (string) ($investigation['stop_reason'] ?? '—'),
                (string) ($investigation['selected_documents'] ?? '—'),
            ));
            foreach ((array) ($investigation['queries'] ?? []) as $n => $roundQuery) {
                $this->line(sprintf('    round %d query: %s', $n + 1, (string) $roundQuery));
            }
        }

        foreach ($payload['results'] as $i => $r) {
            $this->line(sprintf(
                "\n#%d  chunk %s  %s  score=%s",
                $i + 1,
                (string) $r['chunk_id'],
                (string) ($r['document']['source_path'] ?? '?'),
                (string) ($r['rerank_score'] ?? $r['vector_score']),
            ));
            if (($r['heading_path'] ?? '') !== '') {
                $this->line('    '.$r['heading_path']);
            }
            if (! $explain || ! is_array($r['explain'] ?? null)) {
                continue;
            }
            foreach ($r['explain']['lists'] as $l) {
                $this->line(sprintf(
                    '    list %-6s rank=%d weight=%.2f backend=%s rrf=%s',
                    $l['source'],
                    $l['rank'],
                    $l['weight'],
                    (string) $l['backend_score'],
                    (string) ($l['rrf_contribution'] ?? '—'),
                ));
            }
            if (is_array($r['explain']['heuristic'])) {
                $h = $r['explain']['heuristic'];
                $this->line(sprintf(
                    '    heuristic vec=%s kw=%s head=%s tags=%s rec=%s canon+=%s status-=%s mention+=%s => %s',
                    $h['vector'], $h['keyword'], $h['heading'], $h['tags'], $h['recency'],
                    $h['canonical_boost'], $h['status_penalty'], $h['mention_boost'], $h['total'],
                ));
            }
        }
    }
}
