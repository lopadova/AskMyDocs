<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Kb\Agent\DocumentBatchReader;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * W6 (qmd parity) — read several documents in one bounded call. Thin adapter
 * over {@see DocumentBatchReader} (R44). Operator surface: runs without an
 * authenticated principal and regardless of `KB_MULTI_GET_ENABLED` (that flag
 * gates HTTP + MCP only), like `kb:facets`.
 *
 *   php artisan kb:multi-get "policies/**" "#3f9a1c" hr/leave.md --project=hr
 */
final class KbMultiGetCommand extends Command
{
    protected $signature = 'kb:multi-get
        {selectors* : Exact source paths, globs over source_path, or #short-refs}
        {--project= : Restrict to one project_key}
        {--tenant=default : Tenant that owns the documents}
        {--max-bytes= : Total byte budget for the returned content}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read several knowledge-base documents (paths, globs or #refs) in one bounded call.';

    public function handle(DocumentBatchReader $documentBatchReader, TenantContext $tenantContext): int
    {
        $tenant = trim((string) $this->option('tenant'));
        $project = trim((string) $this->option('project'));
        if ($tenant === '') {
            $this->error('--tenant must be a non-empty tenant id.');

            return self::FAILURE;
        }
        $maxBytes = $this->option('max-bytes');
        if ($maxBytes !== null && (! is_numeric($maxBytes) || (int) $maxBytes < 1)) {
            $this->error('--max-bytes must be a positive integer.');

            return self::FAILURE;
        }

        $previous = $tenantContext->current();
        $tenantContext->set($tenant);

        try {
            $payload = $documentBatchReader->read(
                $tenant,
                array_map('strval', (array) $this->argument('selectors')),
                $project === '' ? null : $project,
                $maxBytes === null ? null : (int) $maxBytes,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $tenantContext->set($previous);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $payload['documents'] === [] ? self::FAILURE : self::SUCCESS;
        }

        foreach ($payload['documents'] as $doc) {
            $this->line(sprintf("<info>%s</info>  %s  (%s%s)", $doc['ref'] ?? '—', $doc['source_path'], $doc['bytes'].' bytes', $doc['truncated'] ? ', truncated' : ''));
            $this->line($doc['content']);
            $this->newLine();
        }
        foreach ($payload['omitted'] as $doc) {
            $this->warn(sprintf('omitted (budget): %s %s', $doc['ref'] ?? '—', $doc['source_path']));
        }
        foreach ($payload['missing'] as $miss) {
            $this->warn(sprintf('missing: %s (%s)', $miss['selector'], $miss['reason']));
            foreach ($miss['suggestions'] ?? [] as $s) {
                $this->line('    did you mean: '.$s['source_path']);
            }
        }

        // R14 — nothing readable is a failure, not an empty success.
        return $payload['documents'] === [] ? self::FAILURE : self::SUCCESS;
    }
}
