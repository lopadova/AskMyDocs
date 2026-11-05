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

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            $payload = $retrievalExplainService->explain(
                $query,
                $project === '' ? null : $project,
                max(1, (int) $this->option('limit')),
                intent: trim((string) $this->option('intent')) !== '' ? (string) $this->option('intent') : null,
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

        $this->render($payload, (bool) $this->option('explain'));

        return self::SUCCESS;
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
