<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Canonical identity shared by confirmation and audit. Mailbox and tenant
 * lists are normalized here so CLI argument order cannot change the scope.
 */
final readonly class EmailDatasetOperationContext
{
    /**
     * @param  list<string>  $mailboxes
     * @param  array<string, string>  $tenantByMailbox
     * @param  array<string, scalar|null>  $parameters
     */
    public function __construct(
        public string $operation,
        public string $actor,
        public string $datasetVersion,
        public string $manifestChecksum,
        public array $mailboxes,
        public array $tenantByMailbox,
        public array $parameters,
    ) {}

    /**
     * @return array{
     *   operation: string,
     *   actor: string,
     *   dataset_version: string,
     *   manifest_checksum: string,
     *   mailboxes: list<string>,
     *   tenants: list<string>,
     *   parameters: array<string, scalar|null>
     * }
     */
    public function canonicalPayload(): array
    {
        $mailboxes = array_values(array_unique($this->mailboxes));
        sort($mailboxes, SORT_STRING);
        $tenants = array_values(array_unique(array_values($this->tenantByMailbox)));
        sort($tenants, SORT_STRING);
        $parameters = $this->parameters;
        ksort($parameters, SORT_STRING);

        return [
            'operation' => $this->operation,
            'actor' => $this->actor,
            'dataset_version' => $this->datasetVersion,
            'manifest_checksum' => $this->manifestChecksum,
            'mailboxes' => $mailboxes,
            'tenants' => $tenants,
            'parameters' => $parameters,
        ];
    }
}
