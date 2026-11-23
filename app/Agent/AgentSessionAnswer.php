<?php

declare(strict_types=1);

namespace App\Agent;

/** A session answer paired with the evidence that was available to compose it. */
final readonly class AgentSessionAnswer
{
    public function __construct(public AgentAnswer $answer, public AgentLoopOutcome $sources) {}

    /** Pre-split research workers could already have saved a verified answer. */
    public static function restore(array $checkpoint, AgentLoopOutcome $sources): ?self
    {
        $answer = $checkpoint['answer'] ?? null;
        if (! is_array($answer) || ($checkpoint['source_fingerprint'] ?? null) !== self::fingerprint($sources)) {
            return null;
        }

        return new self(new AgentAnswer($answer['answer'], $answer['locale'], $answer['completeness'], $answer['citations'],
            $answer['tool_sources'], $answer['limitations'], $answer['artifact'], $answer['requires_selection'], $answer['grounding']), $sources);
    }

    private static function fingerprint(AgentLoopOutcome $sources): string
    {
        $envelope = $sources->evidence;
        $keys = [];
        foreach ($envelope->documents() as $document) {
            foreach ($document['evidence'] as $passage) {
                $keys[] = ['document', $document['document_id'], $passage['chunk_id'] ?? null, $passage['evidence_hash']];
            }
        }
        foreach ($envelope->apiTools() as $observation) {
            $keys[] = ['tool', $observation['execution_id'], $observation['evidence_hash']];
        }
        sort($keys);

        return hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR));
    }
}
