<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Database\Seeders\TestEmailFixtures;
use InvalidArgumentException;

/**
 * Durable description of one remote purge that must complete before a
 * checkpoint can be trusted again.
 */
final readonly class EmailSeedPurgeIntent
{
    public const PURGE_DATASET = 'purge_dataset';

    public const PURGE_ALL_SEEDED = 'purge_all_seeded';

    public function __construct(
        public string $mailboxKey,
        public string $operation,
        public string $headerName,
        public string $headerValue,
        public ?string $datasetVersion,
        public ?string $manifestChecksum,
    ) {
        if (preg_match('/^[a-z0-9-]+$/D', $mailboxKey) !== 1) {
            throw new InvalidArgumentException("Mailbox key non valida nel purge intent: {$mailboxKey}");
        }
        if (! in_array($operation, [self::PURGE_DATASET, self::PURGE_ALL_SEEDED], true)) {
            throw new InvalidArgumentException("Operazione purge intent non valida: {$operation}");
        }
        if (preg_match('/^[A-Za-z0-9-]+$/D', $headerName) !== 1) {
            throw new InvalidArgumentException("Header purge intent non valido: {$headerName}");
        }
        if (
            $headerValue === ''
            || strlen($headerValue) > 512
            || preg_match('/[\r\n\x00]/', $headerValue) === 1
        ) {
            throw new InvalidArgumentException('Valore header purge intent non valido.');
        }
        if (($datasetVersion === null) !== ($manifestChecksum === null)) {
            throw new InvalidArgumentException(
                'Dataset version e manifest checksum del purge intent devono essere entrambi presenti o assenti.',
            );
        }
        if (
            $datasetVersion !== null
            && preg_match('/^[a-z0-9-]+$/D', $datasetVersion) !== 1
        ) {
            throw new InvalidArgumentException(
                "Dataset version non valida nel purge intent: {$datasetVersion}",
            );
        }
        if (
            $manifestChecksum !== null
            && preg_match('/^[a-f0-9]{64}$/D', $manifestChecksum) !== 1
        ) {
            throw new InvalidArgumentException('Manifest checksum non valido nel purge intent.');
        }

        if ($operation === self::PURGE_DATASET) {
            if (
                $datasetVersion === null
                || $headerName !== EmailMessageBuilder::DATASET_VERSION_HEADER
                || $headerValue !== $datasetVersion
            ) {
                throw new InvalidArgumentException(
                    'Purge intent dataset incoerente con header e dataset version.',
                );
            }
        } elseif (
            $headerName !== TestEmailFixtures::SEED_HEADER
            || $headerValue !== $mailboxKey
        ) {
            throw new InvalidArgumentException(
                'Purge intent globale incoerente con header e mailbox key.',
            );
        }
    }

    public static function dataset(
        string $mailboxKey,
        string $datasetVersion,
        string $manifestChecksum,
    ): self {
        return new self(
            mailboxKey: $mailboxKey,
            operation: self::PURGE_DATASET,
            headerName: EmailMessageBuilder::DATASET_VERSION_HEADER,
            headerValue: $datasetVersion,
            datasetVersion: $datasetVersion,
            manifestChecksum: $manifestChecksum,
        );
    }

    public static function allSeeded(
        string $mailboxKey,
        ?string $datasetVersion = null,
        ?string $manifestChecksum = null,
    ): self {
        return new self(
            mailboxKey: $mailboxKey,
            operation: self::PURGE_ALL_SEEDED,
            headerName: TestEmailFixtures::SEED_HEADER,
            headerValue: $mailboxKey,
            datasetVersion: $datasetVersion,
            manifestChecksum: $manifestChecksum,
        );
    }

    public function clearsEveryCheckpoint(): bool
    {
        return $this->operation === self::PURGE_ALL_SEEDED;
    }

    /**
     * A recovered intent can satisfy a repeated CLI purge request only when
     * both the remote selector and the local checkpoint-cleanup contract are
     * identical.
     */
    public function isSameRequest(self $other): bool
    {
        return $this->mailboxKey === $other->mailboxKey
            && $this->operation === $other->operation
            && $this->headerName === $other->headerName
            && $this->headerValue === $other->headerValue
            && $this->datasetVersion === $other->datasetVersion
            && $this->manifestChecksum === $other->manifestChecksum;
    }
}
