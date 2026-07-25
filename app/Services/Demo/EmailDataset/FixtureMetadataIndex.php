<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use InvalidArgumentException;

final class FixtureMetadataIndex
{
    public const PREFIX_LENGTH = 2;

    /** @var list<string> */
    private const FIELDS = [
        'fixture_id',
        'company_key',
        'mailbox_key',
        'scenario_type',
        'topic',
        'message_type',
        'thread_id',
        'fact_ids',
        'canonical_sources',
        'truth_state',
        'canary_ids',
        'content_sha256',
    ];

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function entryFromRecord(array $record): array
    {
        $entry = [];
        foreach (self::FIELDS as $field) {
            $entry[$field] = $field === 'content_sha256'
                ? self::contentChecksum(
                    (string) ($record['subject'] ?? ''),
                    (string) ($record['body_text'] ?? ''),
                )
                : ($record[$field] ?? null);
        }

        self::validateEntry($entry);

        return $entry;
    }

    public static function prefix(string $fixtureId): string
    {
        self::assertFixtureId($fixtureId);

        return substr($fixtureId, 0, self::PREFIX_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function validateEntry(array $entry, ?string $expectedPrefix = null): void
    {
        $fields = array_keys($entry);
        $expectedFields = self::FIELDS;
        sort($fields, SORT_STRING);
        sort($expectedFields, SORT_STRING);
        if ($fields !== $expectedFields) {
            throw new InvalidArgumentException('Fixture metadata index entry has an invalid contract.');
        }

        $fixtureId = $entry['fixture_id'];
        if (! is_string($fixtureId)) {
            throw new InvalidArgumentException('Fixture metadata index fixture_id must be a string.');
        }
        self::assertFixtureId($fixtureId);

        if ($expectedPrefix !== null && self::prefix($fixtureId) !== $expectedPrefix) {
            throw new InvalidArgumentException('Fixture metadata index entry is stored in the wrong prefix shard.');
        }

        foreach ([
            'company_key',
            'mailbox_key',
            'scenario_type',
            'topic',
            'message_type',
            'truth_state',
        ] as $field) {
            if (! is_string($entry[$field]) || trim($entry[$field]) === '') {
                throw new InvalidArgumentException("Fixture metadata index {$field} must be a non-empty string.");
            }
        }

        if ($entry['thread_id'] !== null
            && (! is_string($entry['thread_id']) || preg_match('/^[a-f0-9]{32}$/', $entry['thread_id']) !== 1)) {
            throw new InvalidArgumentException('Fixture metadata index thread_id must be null or a stable thread identifier.');
        }

        foreach (['fact_ids', 'canonical_sources', 'canary_ids'] as $field) {
            if (! is_array($entry[$field]) || ! array_is_list($entry[$field])) {
                throw new InvalidArgumentException("Fixture metadata index {$field} must be a list.");
            }

            foreach ($entry[$field] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException("Fixture metadata index {$field} must contain non-empty strings.");
                }
            }
        }

        if (! is_string($entry['content_sha256'])
            || preg_match('/^[a-f0-9]{64}$/', $entry['content_sha256']) !== 1) {
            throw new InvalidArgumentException(
                'Fixture metadata index content_sha256 must be a lowercase SHA-256 hash.',
            );
        }
    }

    public static function contentChecksum(string $subject, string $bodyText): string
    {
        $canonicalBodyText = str_replace(["\r\n", "\r"], "\n", $bodyText);

        return hash('sha256', $subject."\0".trim($canonicalBodyText));
    }

    private static function assertFixtureId(string $fixtureId): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $fixtureId) !== 1) {
            throw new InvalidArgumentException('Fixture metadata index fixture_id must be a lowercase SHA-256 hash.');
        }
    }
}
