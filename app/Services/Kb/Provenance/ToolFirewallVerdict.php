<?php

declare(strict_types=1);

namespace App\Services\Kb\Provenance;

/**
 * Whether this turn's grounding is allowed to reach the tool loop.
 *
 * Deliberately carries the REASON and the offending document ids, not just a
 * boolean. A turn that silently loses its tools is indistinguishable from a
 * model that chose not to call one, and the difference matters to whoever is
 * debugging why an agent stopped acting.
 */
final class ToolFirewallVerdict
{
    /**
     * @param  list<int>  $untrustedDocumentIds
     */
    private function __construct(
        public readonly bool $toolsAllowed,
        public readonly string $reason,
        public readonly array $untrustedDocumentIds = [],
    ) {}

    public static function allowed(string $reason = 'no_untrusted_grounding'): self
    {
        return new self(true, $reason);
    }

    /**
     * @param  list<int>  $documentIds
     */
    public static function blocked(array $documentIds): self
    {
        return new self(false, 'untrusted_grounding', $documentIds);
    }

    /** The firewall is switched off for this deployment. */
    public static function disabled(): self
    {
        return new self(true, 'firewall_disabled');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tools_allowed' => $this->toolsAllowed,
            'reason' => $this->reason,
            'untrusted_document_ids' => $this->untrustedDocumentIds,
        ];
    }

    /**
     * Rebuild from the array form, for the trip through the chat context.
     *
     * Anything unrecognised rebuilds as ALLOWED, matching the pre-firewall
     * behaviour: a turn whose verdict never arrived must not silently lose
     * its tools on the strength of a serialisation bug. Blocking is a
     * decision the firewall makes explicitly, never a decode accident.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null || ! array_key_exists('tools_allowed', $raw) || ! is_bool($raw['tools_allowed'])) {
            return self::allowed('no_verdict');
        }

        if ($raw['tools_allowed']) {
            return self::allowed(is_string($raw['reason'] ?? null) ? $raw['reason'] : 'allowed');
        }

        $ids = $raw['untrusted_document_ids'] ?? [];

        return self::blocked(
            is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [],
        );
    }
}
