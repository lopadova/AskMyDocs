<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class EmailRecordFactory
{
    private DateTimeZone $timezone;

    public function __construct(private DeterministicRandom $random)
    {
        $this->timezone = new DateTimeZone('UTC');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    public function fromGold(
        array $raw,
        array $company,
        string $mailboxKey,
        string $scenarioKey,
        string $datasetVersion,
        int $index,
    ): array {
        $scenario = $company['scenarios'][$scenarioKey];
        $fixtureId = hash('sha256', implode('|', [
            'gold',
            $company['company_key'],
            $mailboxKey,
            (string) $index,
            (string) ($raw['subject'] ?? ''),
            (string) ($raw['from_email'] ?? ''),
            (string) ($raw['date'] ?? ''),
            (string) ($raw['body_text'] ?? ''),
        ]));
        $sentAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) ($raw['date'] ?? ''),
            $this->timezone,
        );
        if ($sentAt === false) {
            throw new InvalidArgumentException("Gold record {$mailboxKey}#{$index} has an invalid date.");
        }

        [$factIds, $sources] = $this->factsFor($company, $scenario);

        return $this->baseRecord(
            fixtureId: $fixtureId,
            datasetVersion: $datasetVersion,
            companyKey: (string) $company['company_key'],
            mailboxKey: $mailboxKey,
            scenarioKey: $scenarioKey,
            topic: (string) $scenario['topic'],
            messageType: 'gold',
            threadId: null,
            messageId: $this->messageId($datasetVersion, $fixtureId),
            inReplyTo: null,
            references: [],
            to: [(string) $company['mailboxes'][$mailboxKey]['to_email']],
            cc: [],
            subject: (string) ($raw['subject'] ?? ''),
            fromName: (string) ($raw['from_name'] ?? ''),
            fromEmail: (string) ($raw['from_email'] ?? ''),
            sentAt: $sentAt,
            bodyText: (string) ($raw['body_text'] ?? ''),
            factIds: $factIds,
            canonicalSources: $sources,
            truthState: 'current',
            canaryIds: [],
        );
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  list<string>  $references
     * @return array<string, mixed>
     */
    public function synthetic(
        array $company,
        DatasetProfile $profile,
        string $mailboxKey,
        string $scenarioKey,
        string $datasetVersion,
        string $catalogVersion,
        int $seed,
        string $coordinate,
        ?string $conversationScope,
        string $messageType,
        ?string $threadId,
        ?string $inReplyTo,
        array $references,
        int $sequence,
        ?DateTimeImmutable $sentAt = null,
        int $threadPosition = 0,
        int $threadSize = 0,
    ): array {
        $scenario = $company['scenarios'][$scenarioKey];
        $scope = implode('|', [$company['company_key'], $mailboxKey, $scenarioKey, $coordinate]);
        $contentScope = implode('|', [
            $company['company_key'],
            $mailboxKey,
            $scenarioKey,
            $conversationScope ?? $coordinate,
        ]);
        $fixtureId = hash('sha256', implode('|', [
            'generated',
            $datasetVersion,
            $catalogVersion,
            (string) $seed,
            $scope,
        ]));
        $messageId = $this->messageId($datasetVersion, $fixtureId);
        $personas = array_values((array) $scenario['persona_ids']);
        $personaId = (string) $this->random->pick($scope.'|persona', $personas);
        $persona = $company['personas'][$personaId];
        $truthState = $this->truthState($scenarioKey, $scope, $threadPosition, $threadSize);
        $sentAt ??= $this->dateFor($profile, $scope);

        $reference = strtoupper(substr(str_replace('-', '', (string) $company['company_key']), 0, 4))
            .'-'.strtoupper(substr(hash('sha256', $contentScope), 0, 10));
        $locations = ['sito Nord', 'sito Centro', 'sito Sud', 'deposito Est', 'area Ovest'];
        $status = strtoupper($truthState === 'current' ? 'verificato' : $truthState);
        [$factIds, $sources] = $this->factsFor($company, $scenario);
        $fact = (string) $company['facts'][$factIds[0]]['statement'];
        $values = [
            '{recipient}' => 'referente di prova',
            '{reference}' => $reference,
            '{status}' => $status,
            '{sequence}' => str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            '{location}' => (string) $this->random->pick($contentScope.'|location', $locations),
            '{amount}' => number_format(
                $this->random->integer($contentScope.'|amount', 2500, 250000) / 100,
                2,
                ',',
                '.',
            ),
            '{fact}' => $fact,
        ];

        $subjects = array_values((array) $scenario['subject_templates']);
        $bodies = array_values((array) $scenario['body_templates']);
        $subject = strtr((string) $this->random->pick($contentScope.'|subject', $subjects), $values);
        if ($inReplyTo !== null) {
            $subject = 'Re: '.$subject;
        }
        $body = strtr((string) $this->random->pick($scope.'|body', $bodies), $values);
        $messageReference = $reference.'-'.strtoupper(substr($fixtureId, 0, 6));
        $body .= "\n\nRiferimento messaggio: {$messageReference}.";

        [$canaryIds, $canaryText] = $this->canaryFor($company, $scenarioKey, $scope);
        if ($canaryText !== null) {
            $body .= "\n\nControllo dataset: {$canaryText}.";
        }

        return $this->baseRecord(
            fixtureId: $fixtureId,
            datasetVersion: $datasetVersion,
            companyKey: (string) $company['company_key'],
            mailboxKey: $mailboxKey,
            scenarioKey: $scenarioKey,
            topic: (string) $scenario['topic'],
            messageType: $messageType,
            threadId: $threadId,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            references: $references,
            to: [(string) $company['mailboxes'][$mailboxKey]['to_email']],
            cc: $this->ccFor($company, $scenario, $scope, $personaId),
            subject: $subject,
            fromName: (string) $persona['name'],
            fromEmail: (string) $persona['email'],
            sentAt: $sentAt,
            bodyText: $body,
            factIds: $factIds,
            canonicalSources: $sources,
            truthState: $truthState,
            canaryIds: $canaryIds,
        );
    }

    public function dateFor(DatasetProfile $profile, string $scope): DateTimeImmutable
    {
        $span = (int) $profile->timelineStart->diff($profile->timelineEnd)->days;
        $safeSpan = max(0, $span - 14);
        $day = $this->random->integer($scope.'|day', 0, $safeSpan);
        $hour = $this->random->integer($scope.'|hour', 8, 17);
        $minute = $this->random->integer($scope.'|minute', 0, 59);
        $second = $this->random->integer($scope.'|second', 0, 59);

        return $profile->timelineStart
            ->setTimezone($this->timezone)
            ->modify("+{$day} days")
            ->setTime($hour, $minute, $second);
    }

    public function messageId(string $datasetVersion, string $fixtureId): string
    {
        return "<{$datasetVersion}.{$fixtureId}@fixtures.askmydocs.invalid>";
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $scenario
     * @return array{0: list<string>, 1: list<string>}
     */
    private function factsFor(array $company, array $scenario): array
    {
        $factIds = array_values(array_map('strval', (array) ($scenario['fact_ids'] ?? [])));
        $sources = [];
        foreach ($factIds as $factId) {
            $sources[] = (string) $company['facts'][$factId]['canonical_source'];
        }

        return [$factIds, array_values(array_unique($sources))];
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array{0: list<string>, 1: ?string}
     */
    private function canaryFor(array $company, string $scenarioKey, string $scope): array
    {
        if ($this->random->integer($scope.'|canary-rate', 0, 99) !== 0) {
            return [[], null];
        }

        $eligible = array_values(array_filter(
            $company['canaries'],
            static fn (array $canary): bool => in_array(
                $scenarioKey,
                array_map('strval', (array) ($canary['scenario_types'] ?? [])),
                true,
            ),
        ));
        if ($eligible === []) {
            return [[], null];
        }

        $canary = $this->random->pick($scope.'|canary-pick', $eligible);

        return [[(string) $canary['canary_id']], (string) $canary['phrase']];
    }

    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $scenario
     * @return list<string>
     */
    private function ccFor(array $company, array $scenario, string $scope, string $senderPersonaId): array
    {
        if ($this->random->integer($scope.'|cc-rate', 0, 9) > 1) {
            return [];
        }

        $candidateIds = array_values(array_filter(
            array_map('strval', (array) $scenario['persona_ids']),
            static fn (string $personaId): bool => $personaId !== $senderPersonaId,
        ));
        if ($candidateIds === []) {
            return [];
        }

        $ccPersonaId = (string) $this->random->pick($scope.'|cc-pick', $candidateIds);

        return [(string) $company['personas'][$ccPersonaId]['email']];
    }

    private function truthState(string $scenarioKey, string $scope, int $threadPosition, int $threadSize): string
    {
        if ($scenarioKey === 'correzioni-rumore') {
            if ($threadSize > 1) {
                if ($threadPosition === 0) {
                    return 'incorrect';
                }

                return $threadPosition === $threadSize - 1 ? 'corrected' : 'proposal';
            }

            return $this->random->integer($scope.'|truth', 0, 1) === 0 ? 'corrected' : 'superseded';
        }

        return $this->random->integer($scope.'|truth', 0, 19) === 0 ? 'proposal' : 'current';
    }

    /**
     * @param  list<string>  $references
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $factIds
     * @param  list<string>  $canonicalSources
     * @param  list<string>  $canaryIds
     * @return array<string, mixed>
     */
    private function baseRecord(
        string $fixtureId,
        string $datasetVersion,
        string $companyKey,
        string $mailboxKey,
        string $scenarioKey,
        string $topic,
        string $messageType,
        ?string $threadId,
        string $messageId,
        ?string $inReplyTo,
        array $references,
        array $to,
        array $cc,
        string $subject,
        string $fromName,
        string $fromEmail,
        DateTimeImmutable $sentAt,
        string $bodyText,
        array $factIds,
        array $canonicalSources,
        string $truthState,
        array $canaryIds,
    ): array {
        return [
            'schema_version' => '2.0',
            'fixture_id' => $fixtureId,
            'dataset_version' => $datasetVersion,
            'company_key' => $companyKey,
            'mailbox_key' => $mailboxKey,
            'scenario_type' => $scenarioKey,
            'topic' => $topic,
            'message_type' => $messageType,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'date' => $sentAt->format('Y-m-d H:i:s'),
            'sent_at' => $sentAt->format(DATE_ATOM),
            'internal_date' => $sentAt->format(DATE_ATOM),
            'body_text' => $bodyText,
            'body_html' => null,
            'headers' => [
                'X-AskMyDocs-Fixture-Id' => $fixtureId,
                'X-AskMyDocs-Dataset-Version' => $datasetVersion,
                'X-AskMyDocs-Synthetic' => 'true',
            ],
            'attachments' => [],
            'fact_ids' => $factIds,
            'canonical_sources' => $canonicalSources,
            'truth_state' => $truthState,
            'canary_ids' => $canaryIds,
            'sensitivity' => 'synthetic_non_real',
        ];
    }
}
