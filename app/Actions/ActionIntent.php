<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class ActionIntent
{
    /**
     * @param array<string, mixed> $args
     * @param list<string> $evidenceRefs
     */
    public function __construct(
        public string $actionId,
        public string $schemaVersion,
        public string $tenantId,
        public string $principalId,
        public string $resource,
        public string $effect,
        public array $args,
        public ?string $target,
        public string $grant,
        public string $assurance,
        public array $evidenceRefs,
        public ?string $reservationRef,
        public string $argsDigest,
    ) {}

    /** @param array<string, mixed> $proposal */
    public static function fromModelProposal(
        string $actionId,
        string $tenantId,
        string $principalId,
        array $proposal,
        string $grant,
        string $assurance,
        ?ActionCanonicalizer $canonicalizer = null,
    ): self {
        $args = is_array($proposal['args'] ?? null) ? $proposal['args'] : [];
        foreach (['tenant_id', 'principal_id', 'grant', 'assurance', 'reservation_ref'] as $reserved) {
            unset($args[$reserved]);
        }

        $canonicalizer ??= new ActionCanonicalizer;

        return new self(
            actionId: $actionId,
            schemaVersion: 'action-intent.v1',
            tenantId: $tenantId,
            principalId: $principalId,
            resource: (string) ($proposal['resource'] ?? ''),
            effect: (string) ($proposal['effect'] ?? ''),
            args: $args,
            target: isset($proposal['target']) ? (string) $proposal['target'] : null,
            grant: $grant,
            assurance: $assurance,
            evidenceRefs: is_array($proposal['evidenceRefs'] ?? null) ? array_values(array_filter($proposal['evidenceRefs'], 'is_string')) : [],
            reservationRef: null,
            argsDigest: $canonicalizer->digest($args),
        );
    }
}
