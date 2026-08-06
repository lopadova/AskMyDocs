<?php

declare(strict_types=1);

namespace App\Services\Demo;

use DateTimeImmutable;
use Database\Seeders\TestEmailFixtures;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Pure RFC822 renderer for both legacy gold fixtures and schema-v2 records.
 */
final class EmailMessageBuilder
{
    public const DATASET_VERSION_HEADER = 'X-AskMyDocs-Dataset-Version';

    public const FIXTURE_ID_HEADER = 'X-AskMyDocs-Fixture-Id';

    public const SCENARIO_HEADER = 'X-AskMyDocs-Scenario';

    private const FIXTURE_DOMAIN = 'fixtures.askmydocs.invalid';

    private const LEGACY_FIXTURE_DOMAIN = 'gold-fixtures.askmydocs.invalid';

    /**
     * Backwards-compatible convenience method used by the legacy unit tests.
     *
     * @param  array<string, mixed>  $fixture
     */
    public function build(MailboxTarget $target, array $fixture): string
    {
        return $this->prepare($target, $fixture, 1)->raw;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    public function prepare(
        MailboxTarget $target,
        array $fixture,
        int $sequence,
        bool $verifyBeforeAppend = false,
    ): PreparedEmailMessage {
        if ($sequence < 1) {
            throw new InvalidArgumentException('La sequenza del messaggio deve essere >= 1.');
        }

        $isSchemaV2 = isset($fixture['fixture_id'], $fixture['dataset_version'], $fixture['message_id']);
        $datasetVersion = (string) ($fixture['dataset_version'] ?? 'gold-v1');
        $this->assertToken($datasetVersion, 'dataset_version', '/^[a-z0-9-]+$/');

        $fixtureId = isset($fixture['fixture_id'])
            ? (string) $fixture['fixture_id']
            : $this->legacyFixtureId($target, $fixture, $sequence);
        $this->assertToken($fixtureId, 'fixture_id', '/^[a-f0-9]{64}$/');

        // The reserved fixtures.askmydocs.invalid namespace is also the trusted
        // ingestion marker that disables expensive post-ingest AI jobs. Keep
        // the historical hand-curated JSON layer on a separate stable domain;
        // only records explicitly normalised to schema v2 opt into that gate.
        $messageId = $datasetVersion.'.'.$fixtureId.'@'.(
            $isSchemaV2 ? self::FIXTURE_DOMAIN : self::LEGACY_FIXTURE_DOMAIN
        );
        if (isset($fixture['message_id'])) {
            $providedMessageId = $this->normaliseMessageId((string) $fixture['message_id']);
            if ($providedMessageId !== $messageId) {
                throw new InvalidArgumentException(
                    "message_id '{$providedMessageId}' non coerente con dataset_version e fixture_id.",
                );
            }
        }

        $subject = $this->requiredString($fixture, 'subject');
        $fromEmail = $this->requiredString($fixture, 'from_email');
        $fromName = $this->requiredString($fixture, 'from_name');
        $bodyText = $this->requiredString($fixture, 'body_text');
        $sentAt = new DateTimeImmutable($this->requiredString($fixture, 'date'));
        // APPEND arrival time must be current even when the narrative Date
        // header belongs to the historical 2024-2026 case-study timeline.
        // Reusing the historical date as IMAP INTERNALDATE makes a newly
        // appended fixture invisible to an existing installation whose
        // incremental sync applies SINCE(last_sync_at).
        $internalDate = now()->toDateTimeImmutable();

        $recipients = $this->addressList($fixture['to'] ?? [$target->email], 'to');
        $email = (new Email)
            ->from(new Address($fromEmail, $fromName))
            ->to(...$recipients)
            ->subject($subject)
            ->date($sentAt)
            ->text($bodyText);

        $cc = $this->addressList($fixture['cc'] ?? [], 'cc');
        if ($cc !== []) {
            $email->cc(...$cc);
        }

        if (isset($fixture['body_html']) && is_string($fixture['body_html']) && $fixture['body_html'] !== '') {
            $email->html($fixture['body_html']);
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);

        if (isset($fixture['in_reply_to']) && $fixture['in_reply_to'] !== null) {
            $headers->addIdHeader(
                'In-Reply-To',
                $this->normaliseFixtureReference((string) $fixture['in_reply_to']),
            );
        }

        $references = $fixture['references'] ?? [];
        if (! is_array($references)) {
            throw new InvalidArgumentException('references deve essere una lista.');
        }
        if ($references !== []) {
            $headers->addIdHeader('References', array_map(
                fn (mixed $reference): string => $this->normaliseFixtureReference((string) $reference),
                array_values($references),
            ));
        }

        $headers->addTextHeader(TestEmailFixtures::SEED_HEADER, $target->mailboxKey);
        $headers->addTextHeader(self::DATASET_VERSION_HEADER, $datasetVersion);
        $headers->addTextHeader(self::FIXTURE_ID_HEADER, $fixtureId);

        $scenario = $fixture['scenario_type'] ?? null;
        if (is_string($scenario) && $scenario !== '') {
            $this->assertSafeHeaderValue($scenario, 'scenario_type');
            $headers->addTextHeader(self::SCENARIO_HEADER, $scenario);
        }

        $this->addAllowlistedHeaders($email, $fixture['headers'] ?? []);
        $this->addAttachments($email, $fixture['attachments'] ?? []);

        return new PreparedEmailMessage(
            raw: $email->toString(),
            internalDate: $internalDate,
            fixtureId: $fixtureId,
            messageId: '<'.$messageId.'>',
            sequence: $sequence,
            subject: $subject,
            datasetVersion: $datasetVersion,
            verifyBeforeAppend: $verifyBeforeAppend,
        );
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function legacyFixtureId(MailboxTarget $target, array $fixture, int $sequence): string
    {
        try {
            $payload = json_encode(
                [
                    'mailbox_key' => $target->mailboxKey,
                    'sequence' => $sequence,
                    'fixture' => $fixture,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Fixture legacy non serializzabile.', previous: $e);
        }

        return hash('sha256', $payload);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function requiredString(array $fixture, string $field): string
    {
        $value = $fixture[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Campo fixture '{$field}' mancante o vuoto.");
        }

        return $value;
    }

    /**
     * @return list<Address>
     */
    private function addressList(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$field} deve essere una lista di indirizzi.");
        }

        $addresses = [];
        foreach (array_values($value) as $address) {
            if (! is_string($address) || trim($address) === '') {
                throw new InvalidArgumentException("{$field} contiene un indirizzo non valido.");
            }
            $addresses[] = new Address($address);
        }

        if ($field === 'to' && $addresses === []) {
            throw new InvalidArgumentException('to deve contenere almeno un indirizzo.');
        }

        return $addresses;
    }

    private function normaliseFixtureReference(string $messageId): string
    {
        $normalised = $this->normaliseMessageId($messageId);
        if (! preg_match(
            '/^[a-z0-9-]+\.[a-f0-9]{64}@fixtures\.askmydocs\.invalid$/',
            $normalised,
        )) {
            throw new InvalidArgumentException("Riferimento Message-ID non valido: '{$messageId}'.");
        }

        return $normalised;
    }

    private function normaliseMessageId(string $messageId): string
    {
        $messageId = trim($messageId);
        if (str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = substr($messageId, 1, -1);
        }

        $this->assertSafeHeaderValue($messageId, 'message_id');

        return $messageId;
    }

    /**
     * @param  array<string, mixed>|mixed  $fixtureHeaders
     */
    private function addAllowlistedHeaders(Email $email, mixed $fixtureHeaders): void
    {
        if (! is_array($fixtureHeaders)) {
            throw new InvalidArgumentException('headers deve essere un oggetto.');
        }

        $reserved = array_map('strtolower', [
            TestEmailFixtures::SEED_HEADER,
            self::DATASET_VERSION_HEADER,
            self::FIXTURE_ID_HEADER,
            self::SCENARIO_HEADER,
            'Message-ID',
            'In-Reply-To',
            'References',
            'From',
            'To',
            'Cc',
            'Bcc',
            'Subject',
            'Date',
        ]);

        foreach ($fixtureHeaders as $name => $value) {
            if (! is_string($name) || ! is_scalar($value)) {
                throw new InvalidArgumentException('Header fixture non valido.');
            }

            if (
                ! in_array($name, ['Auto-Submitted', 'Precedence', 'List-Unsubscribe'], true)
                && preg_match('/^X-AskMyDocs-[A-Za-z0-9-]+$/', $name) !== 1
            ) {
                throw new InvalidArgumentException("Header fixture non consentito: '{$name}'.");
            }
            if (in_array(strtolower($name), $reserved, true)) {
                continue;
            }

            $headerValue = (string) $value;
            $this->assertSafeHeaderValue($headerValue, $name);
            $email->getHeaders()->addTextHeader($name, $headerValue);
        }
    }

    private function addAttachments(Email $email, mixed $attachments): void
    {
        if (! is_array($attachments)) {
            throw new InvalidArgumentException('attachments deve essere una lista.');
        }

        foreach (array_values($attachments) as $attachment) {
            if (! is_array($attachment)) {
                throw new InvalidArgumentException('Attachment fixture non valido.');
            }

            $name = $attachment['filename'] ?? null;
            $encoded = $attachment['content_base64'] ?? null;
            if (! is_string($name) || $name === '' || ! is_string($encoded)) {
                throw new InvalidArgumentException('Attachment richiede filename e content_base64.');
            }

            $content = base64_decode($encoded, true);
            if ($content === false) {
                throw new InvalidArgumentException("Attachment '{$name}' non è base64 valido.");
            }

            $contentType = $attachment['content_type'] ?? null;
            $email->attach($content, $name, is_string($contentType) ? $contentType : null);
        }
    }

    private function assertToken(string $value, string $field, string $pattern): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("{$field} non valido: '{$value}'.");
        }
    }

    private function assertSafeHeaderValue(string $value, string $field): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException("{$field} contiene caratteri di controllo non consentiti.");
        }
    }
}
