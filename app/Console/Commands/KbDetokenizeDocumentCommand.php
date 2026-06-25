<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminCommandAudit;
use App\Services\Kb\Pii\DetokenizeService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v8.23 (Ciclo 4) — PHP/CLI surface (R44) for re-identifying a tokenised KB
 * document, over the SAME {@see DetokenizeService} core as the HTTP endpoint and
 * the {@see \App\Mcp\Tools\KbDetokenizeTool} MCP tool.
 *
 * Operator-level: shell access IS the authorization (mirroring the maintenance
 * commands), so it does not enforce the `pii.detokenize` Spatie permission — but
 * it STILL writes an `admin_command_audit` row (surface=cli) so the unmask is
 * forensically traceable, and it honours the `tokenise` strategy preflight.
 */
final class KbDetokenizeDocumentCommand extends Command
{
    protected $signature = 'kb:detokenize-document
                            {id : The knowledge document id to re-identify}
                            {--tenant=default : Tenant that owns the document}';

    protected $description = 'Re-identify (detokenise) a tokenised KB document\'s chunks for an operator.';

    public function handle(DetokenizeService $detokenizer, TenantContext $tenants): int
    {
        if (! $detokenizer->isTokeniseActive()) {
            $this->error('PII detokenisation requires the `tokenise` strategy (PII_REDACTOR_STRATEGY=tokenise).');

            return self::FAILURE;
        }

        $id = (int) $this->argument('id');
        $tenant = (string) $this->option('tenant');

        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            $document = $detokenizer->findDocument($id);
            if ($document === null) {
                $this->error("No knowledge document #{$id} in tenant '{$tenant}'.");

                return self::FAILURE;
            }

            $result = $detokenizer->detokenizeDocument($document);

            $detokenizer->audit(
                AdminCommandAudit::STATUS_COMPLETED,
                null,
                ['document_id' => $id, 'surface' => 'cli', 'tenant' => $tenant],
            );
        } finally {
            $tenants->set($previous);
        }

        $this->info(sprintf(
            'Document #%d (%s): %d token(s), %d resolved, %d unresolved.',
            $document->id,
            (string) $document->project_key,
            $result['token_count'],
            $result['resolved_count'],
            count($result['unresolved_tokens']),
        ));

        foreach ($result['chunks'] as $chunk) {
            $this->line(sprintf('--- chunk %d (%s) ---', $chunk['chunk_order'], $chunk['heading_path']));
            $this->line($chunk['text']);
        }

        return self::SUCCESS;
    }
}
