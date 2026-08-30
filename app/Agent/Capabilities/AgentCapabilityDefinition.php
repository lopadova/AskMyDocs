<?php

declare(strict_types=1);

namespace App\Agent\Capabilities;

use JsonSerializable;

final readonly class AgentCapabilityDefinition implements JsonSerializable
{
    /**
     * @param list<string> $requiredInputs
     * @param list<string> $identityFields
     * @param list<string> $nextTools
     * @param list<string> $intentTags
     */
    public function __construct(
        public string $tool,
        public string $source,
        public string $entity,
        public string $operation,
        public array $requiredInputs,
        public ?string $collectionPath,
        public array $identityFields,
        public array $nextTools,
        public array $intentTags,
        public bool $readOnly,
        public bool $idempotent,
        public bool $confirmationRequired,
        public string $risk,
        public ?array $outputSchema,
        public string $inference,
    ) {}

    /** @return array<string,mixed> */
    public function compact(): array
    {
        return [
            'tool' => $this->tool,
            'source' => $this->source,
            'entity' => $this->entity,
            'operation' => $this->operation,
            'required_inputs' => $this->requiredInputs,
            'collection_path' => $this->collectionPath,
            'identity_fields' => $this->identityFields,
            'next_tools' => $this->nextTools,
            'intent_tags' => $this->intentTags,
            'read_only' => $this->readOnly,
            'idempotent' => $this->idempotent,
            'confirmation_required' => $this->confirmationRequired,
            'risk' => $this->risk,
            'inference' => $this->inference,
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->compact() + ['output_schema' => $this->outputSchema];
    }
}
