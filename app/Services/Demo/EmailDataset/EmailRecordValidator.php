<?php

declare(strict_types=1);

namespace App\Services\Demo\EmailDataset;

use DateTimeImmutable;
use InvalidArgumentException;

final class EmailRecordValidator
{
    private const REQUIRED_STRING_FIELDS = [
        'schema_version',
        'fixture_id',
        'dataset_version',
        'company_key',
        'mailbox_key',
        'scenario_type',
        'topic',
        'message_type',
        'message_id',
        'subject',
        'from_name',
        'from_email',
        'date',
        'sent_at',
        'internal_date',
        'body_text',
        'truth_state',
        'sensitivity',
    ];

    private const ALLOWED_FIELDS = [
        'schema_version',
        'fixture_id',
        'dataset_version',
        'company_key',
        'mailbox_key',
        'scenario_type',
        'topic',
        'message_type',
        'thread_id',
        'message_id',
        'in_reply_to',
        'references',
        'to',
        'cc',
        'subject',
        'from_name',
        'from_email',
        'date',
        'sent_at',
        'internal_date',
        'body_text',
        'body_html',
        'headers',
        'attachments',
        'fact_ids',
        'canonical_sources',
        'truth_state',
        'canary_ids',
        'sensitivity',
    ];

    /**
     * @param  array<string, mixed>  $record
     */
    public function validate(array $record): void
    {
        $unknown = array_values(array_diff(array_keys($record), self::ALLOWED_FIELDS));
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Email record contains unsupported fields: '.implode(', ', $unknown).'.',
            );
        }

        foreach (self::REQUIRED_STRING_FIELDS as $field) {
            if (! isset($record[$field]) || ! is_string($record[$field]) || trim($record[$field]) === '') {
                throw new InvalidArgumentException("Email record field {$field} must be a non-empty string.");
            }
        }

        if ($record['schema_version'] !== '2.0') {
            throw new InvalidArgumentException('Email record schema_version must be 2.0.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $record['fixture_id'])) {
            throw new InvalidArgumentException('Email record fixture_id must be a lowercase SHA-256 hash.');
        }

        if (! preg_match('/^[a-z0-9-]+$/', $record['dataset_version'])) {
            throw new InvalidArgumentException('Email record dataset_version has unsafe characters.');
        }

        $messageIdPattern = '/^<'.preg_quote($record['dataset_version'], '/')
            .'\\.[a-f0-9]{64}@fixtures\\.askmydocs\\.invalid>$/';
        if (! preg_match($messageIdPattern, $record['message_id'])) {
            throw new InvalidArgumentException('Email record message_id does not encode dataset_version and fixture_id.');
        }

        $expectedMessageId = '<'.$record['dataset_version'].'.'.$record['fixture_id'].'@fixtures.askmydocs.invalid>';
        if ($record['message_id'] !== $expectedMessageId) {
            throw new InvalidArgumentException('Email record message_id fixture component does not match fixture_id.');
        }

        foreach (['to', 'cc', 'references', 'fact_ids', 'canonical_sources', 'canary_ids', 'attachments'] as $field) {
            if (! isset($record[$field]) || ! is_array($record[$field]) || ! array_is_list($record[$field])) {
                throw new InvalidArgumentException("Email record field {$field} must be a list.");
            }
        }

        if ($record['to'] === []) {
            throw new InvalidArgumentException('Email record must have at least one recipient.');
        }

        foreach (['references', 'fact_ids', 'canonical_sources', 'canary_ids'] as $field) {
            foreach ($record[$field] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException("Email record {$field} must contain non-empty strings.");
                }
            }
        }

        foreach (array_merge([$record['from_email']], $record['to'], $record['cc']) as $email) {
            if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException("Email record contains an invalid address: {$email}");
            }

            $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
            if ($domain !== 'example.com'
                && ! str_ends_with($domain, '.example.com')
                && ! str_ends_with($domain, '.example')) {
                throw new InvalidArgumentException("Email record contains a non-reserved address: {$email}");
            }
        }

        if (! is_array($record['headers'] ?? null)) {
            throw new InvalidArgumentException('Email record headers must be an object.');
        }
        $allowedHeaders = [
            'Auto-Submitted',
            'Precedence',
            'List-Unsubscribe',
            'X-AskMyDocs-Fixture-Id',
            'X-AskMyDocs-Dataset-Version',
            'X-AskMyDocs-Synthetic',
        ];
        foreach ($record['headers'] as $name => $value) {
            if (
                ! is_string($name)
                || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1
                || (
                    ! in_array($name, $allowedHeaders, true)
                    && preg_match('/^X-AskMyDocs-[A-Za-z0-9-]+$/', $name) !== 1
                )
                || ! is_scalar($value)
                || str_contains((string) $value, "\r")
                || str_contains((string) $value, "\n")
            ) {
                throw new InvalidArgumentException('Email record contains an unsafe header.');
            }
        }

        if (($record['body_html'] ?? null) !== null && ! is_string($record['body_html'])) {
            throw new InvalidArgumentException('Email record body_html must be a string or null.');
        }

        if (! in_array($record['truth_state'], ['current', 'superseded', 'proposal', 'incorrect', 'corrected'], true)) {
            throw new InvalidArgumentException('Email record truth_state is invalid.');
        }

        if (! in_array($record['message_type'], ['gold', 'thread', 'transactional', 'report'], true)) {
            throw new InvalidArgumentException('Email record message_type is invalid.');
        }

        if ($record['sensitivity'] !== 'synthetic_non_real') {
            throw new InvalidArgumentException('Case-study email sensitivity must be synthetic_non_real.');
        }

        $legacyDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $record['date']);
        if (
            $legacyDate === false
            || $legacyDate->format('Y-m-d H:i:s') !== $record['date']
        ) {
            throw new InvalidArgumentException('Email record contains an invalid date.');
        }
        foreach (['sent_at', 'internal_date'] as $field) {
            $isoDate = DateTimeImmutable::createFromFormat(DATE_ATOM, $record[$field]);
            if ($isoDate === false || $isoDate->format(DATE_ATOM) !== $record[$field]) {
                throw new InvalidArgumentException("Email record contains an invalid ISO date in {$field}.");
            }
        }

        if ($record['message_type'] === 'thread') {
            if (! is_string($record['thread_id'] ?? null)
                || ! preg_match('/^[a-f0-9]{32}$/', $record['thread_id'])) {
                throw new InvalidArgumentException('Thread email record must have a stable thread_id.');
            }
        } elseif (($record['thread_id'] ?? null) !== null) {
            throw new InvalidArgumentException('Standalone email record must not have a thread_id.');
        }

        if (($record['in_reply_to'] ?? null) !== null
            && (! is_string($record['in_reply_to']) || ! in_array($record['in_reply_to'], $record['references'], true))) {
            throw new InvalidArgumentException('Reply parent must occur in the References chain.');
        }

        foreach ($record['references'] as $reference) {
            if (preg_match('/^<[a-z0-9-]+\.[a-f0-9]{64}@fixtures\.askmydocs\.invalid>$/', $reference) !== 1) {
                throw new InvalidArgumentException('Email record contains an invalid References Message-ID.');
            }
        }

        foreach ($record['attachments'] as $attachment) {
            $attachmentFields = is_array($attachment) ? array_keys($attachment) : [];
            $decoded = is_array($attachment) && is_string($attachment['content_base64'] ?? null)
                ? base64_decode($attachment['content_base64'], true)
                : false;
            if (
                ! is_array($attachment)
                || ! is_string($attachment['filename'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$/', $attachment['filename']) !== 1
                || ! is_string($attachment['content_base64'] ?? null)
                || array_diff($attachmentFields, ['filename', 'content_base64', 'content_type']) !== []
                || (
                    isset($attachment['content_type'])
                    && (
                        ! is_string($attachment['content_type'])
                        || $attachment['content_type'] === ''
                        || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $attachment['content_type']) !== 1
                    )
                )
                || $decoded === false
                || strlen($decoded) > 1_048_576
            ) {
                throw new InvalidArgumentException('Email record contains an invalid attachment.');
            }
        }
    }
}
