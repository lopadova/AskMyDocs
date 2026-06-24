<?php

declare(strict_types=1);

namespace App\Services\Kb\Pii;

use App\Models\AdminCommandAudit;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;

/**
 * v8.23 (Ciclo 4) — GDPR Art.17 right-to-erasure via crypto-shred of the
 * per-tenant reversible token vault.
 *
 * The ONE core behind the tri-surface (HTTP + CLI + MCP) `kb:erase-subject`
 * capability (R44) AND the DSAR Art.17 flow ({@see \App\Compliance\AskMyDocsUserDataDeleter}).
 *
 * Erasure deletes the `pii_token_maps` rows whose `original` matches the
 * subject's PII value(s) in the active tenant. Because the vault is the ONLY
 * copy that links a `[tok:...]` surrogate back to the real value, destroying it
 * is a crypto-shred: every surrogate left in the KB / chat / embeddings becomes
 * permanently unresolvable (detokenise returns it unresolved), so the indexed
 * data is no longer personal data linkable to the subject — without rewriting
 * every downstream row. Tenant-scoped (R30): a value is erased ONLY within the
 * given tenant, never across tenants.
 *
 * Authorization (`pii.erase`) lives at each surface; this core performs the
 * shred + provides a shared audit helper.
 */
final class SubjectErasureService
{
    /** The audit command tag for an erasure attempt. */
    public const AUDIT_COMMAND = 'pii.erase';

    /**
     * Crypto-shred the vault entries for the given PII value(s) in a tenant.
     * Returns the number of vault rows destroyed. A no-op (returns 0) for an
     * empty value set or a non-persistent (memory/cache) vault where the table
     * holds nothing.
     *
     * @param  list<string>  $values  the subject's identifying PII value(s)
     */
    public function eraseValues(string $tenantId, array $values): int
    {
        $values = $this->normalizeValues($values);
        if ($values === []) {
            return 0;
        }

        return PiiTokenMap::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('original', $values)
            ->delete();
    }

    /**
     * The vault entries (token + detector + original) held for the given PII
     * value(s) in a tenant — surfaced by the DSAR Art.15 export so a subject can
     * see exactly which surrogates the vault can still reverse to their data.
     *
     * @param  list<string>  $values
     * @return list<array{token:string, detector:string, original:string}>
     */
    public function snapshotValues(string $tenantId, array $values): array
    {
        $values = $this->normalizeValues($values);
        if ($values === []) {
            return [];
        }

        return PiiTokenMap::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('original', $values)
            ->get(['token', 'detector', 'original'])
            ->map(static fn (PiiTokenMap $row): array => [
                'token' => (string) $row->token,
                'detector' => (string) $row->detector,
                'original' => (string) $row->original,
            ])
            ->all();
    }

    /**
     * Write an immutable audit row for a standalone erasure attempt
     * (completed OR rejected). The DSAR-flow erasure is recorded by the
     * package's `dsar_requests` row instead, so it does not call this.
     *
     * @param  array<string,mixed>  $args     goes to `args_json`
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

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeValues(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }
}
