<?php

declare(strict_types=1);

namespace App\Services\Kb\Pii;

use App\Models\AdminCommandAudit;
use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Support\TenantContext;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Padosoft\PiiRedactor\TokenStore\TokenResolutionService;

/**
 * v8.23 (Ciclo 4) — the ONE core behind the tri-surface (HTTP + CLI + MCP)
 * KB-document re-identification capability (R44).
 *
 * Reverses the `[tok:detector:hex]` surrogates stored in a document's
 * `chunk_text` back to the originals held in the per-tenant vault, for an
 * authorised operator. Authorization (the `pii.detokenize` permission) lives at
 * each surface — this core only performs the reversal (tenant-scoped via the
 * package TokenStore resolver, R30) and provides a shared audit helper so every
 * unmask attempt is forensically traceable in `admin_command_audit`.
 *
 * Re-identification is JIT and NEVER cached: callers receive plaintext only for
 * the single request, and the redacted form remains the system-of-record.
 */
final class DetokenizeService
{
    /** The audit command tag shared with the chat-log detokenise path. */
    public const AUDIT_COMMAND = 'pii.detokenize';

    public function __construct(
        private readonly TokenResolutionService $resolver,
    ) {}

    /**
     * Detokenisation is only meaningful under the reversible `tokenise`
     * strategy — under mask/hash/drop no token map was ever minted.
     */
    public function isTokeniseActive(): bool
    {
        return app(RedactionStrategy::class) instanceof TokeniseStrategy;
    }

    /**
     * Resolve a KB document for re-identification, scoped to the active tenant
     * (R30). The per-project {@see AccessScopeScope} read ACL is intentionally
     * bypassed: detokenisation is a privileged, permission-gated, fully-audited
     * compliance operation (e.g. a DPO servicing a DSAR) that must reach ANY
     * document in its tenant, not only the projects the caller can browse.
     * Returns null when the id is absent in this tenant (caller maps to 404).
     */
    public function findDocument(int $documentId): ?KnowledgeDocument
    {
        return KnowledgeDocument::query()
            ->withoutGlobalScope(AccessScopeScope::class)
            ->forTenant(app(TenantContext::class)->current())
            ->find($documentId);
    }

    /**
     * Re-identify every chunk of a KB document. The caller MUST have already
     * loaded the document tenant-scoped (R30) and checked authorization.
     *
     * @return array{
     *   chunks: list<array{chunk_order:int, heading_path:string, text:string, token_count:int, resolved_count:int}>,
     *   token_count:int, resolved_count:int, unresolved_tokens: list<string>
     * }
     */
    public function detokenizeDocument(KnowledgeDocument $document): array
    {
        $tokenCount = 0;
        $resolvedCount = 0;
        $unresolved = [];
        $chunks = [];

        foreach ($document->chunks()->orderBy('chunk_order')->get() as $chunk) {
            $result = $this->resolver->detokeniseString((string) $chunk->chunk_text);
            $tokenCount += $result->tokenCount;
            $resolvedCount += $result->resolvedCount;
            $unresolved = array_merge($unresolved, $result->unresolvedTokens);

            $chunks[] = [
                'chunk_order' => (int) $chunk->chunk_order,
                'heading_path' => (string) $chunk->heading_path,
                'text' => $result->output,
                'token_count' => $result->tokenCount,
                'resolved_count' => $result->resolvedCount,
            ];
        }

        return [
            'chunks' => $chunks,
            'token_count' => $tokenCount,
            'resolved_count' => $resolvedCount,
            'unresolved_tokens' => array_values(array_unique($unresolved)),
        ];
    }

    /**
     * Write an immutable audit row for an unmask attempt (completed OR rejected).
     * Shared by every surface so the forensic trail is uniform.
     *
     * @param  array<string,mixed>  $args     goes to `args_json` (document id + surface)
     * @param  array<string,mixed>  $context  optional `client_ip` / `user_agent`
     */
    public function audit(string $status, ?int $userId, array $args, array $context = [], ?string $error = null): void
    {
        AdminCommandAudit::query()->create([
            'user_id' => $userId,
            'command' => self::AUDIT_COMMAND,
            'args_json' => $args,
            'status' => $status,
            'error_message' => $error,
            'started_at' => now(),
            'completed_at' => now(),
            'client_ip' => $context['client_ip'] ?? null,
            'user_agent' => isset($context['user_agent']) ? substr((string) $context['user_agent'], 0, 255) : null,
        ]);
    }
}
