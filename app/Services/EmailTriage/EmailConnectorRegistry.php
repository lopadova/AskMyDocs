<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/** Provider-neutral metadata projection; IMAP keeps its historical identity. */
final class EmailConnectorRegistry
{
    public static function supports(ConnectorInstallation|string $connector): bool
    {
        return self::supportsKey($connector instanceof ConnectorInstallation ? $connector->connector_name : $connector);
    }

    public static function supportsKey(string $key): bool
    {
        return in_array($key, ['imap', 'gmail', 'microsoft-mail'], true);
    }

    public static function resolveProject(ConnectorInstallation $installation): string
    {
        if (is_string($installation->project_key) && $installation->project_key !== '') {
            return $installation->project_key;
        }
        $legacy = data_get($installation->config_json, 'project_key');
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }
        $default = config('kb.ingest.default_project');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    public static function isApi(string $key): bool
    {
        return in_array($key, ['gmail', 'microsoft-mail'], true);
    }

    public static function messageKey(array $metadata): ?string
    {
        return self::string($metadata, self::isApi((string) ($metadata['connector'] ?? '')) ? 'email_message_key' : 'imap_doc_key');
    }

    public static function messageId(array $metadata): ?string
    {
        return self::string($metadata, self::isApi((string) ($metadata['connector'] ?? '')) ? 'email_message_id' : 'imap_message_id');
    }

    public static function parentMessageId(array $metadata): ?string
    {
        return self::string($metadata, 'attachment_of_message_id') ?? self::messageId($metadata);
    }

    public static function date(array $metadata): ?string
    {
        return self::string($metadata, 'email_date') ?? self::string($metadata, 'imap_date')
            ?? (is_string(data_get($metadata, 'imap.date')) ? data_get($metadata, 'imap.date') : null);
    }

    /** @return list<string> */
    public static function folders(array $metadata): array
    {
        if (self::isApi((string) ($metadata['connector'] ?? ''))) {
            return array_values(array_filter((array) ($metadata['email_folder_ids'] ?? []), 'is_string'));
        }
        $mailbox = self::string($metadata, 'imap_mailbox');

        return $mailbox === null ? [] : [$mailbox];
    }

    /** @return list<string> */
    public static function folderNames(array $metadata): array
    {
        return self::isApi((string) ($metadata['connector'] ?? ''))
            ? array_values(array_filter((array) ($metadata['email_folder_names'] ?? []), 'is_string'))
            : self::folders($metadata);
    }

    public static function isAttachment(array $metadata): bool
    {
        return array_key_exists('attachment_filename', $metadata);
    }

    public static function attachmentCount(array $metadata): int
    {
        return max(0, (int) ($metadata['email_attachment_count'] ?? $metadata['imap_attachment_count'] ?? data_get($metadata, 'imap.attachment_count', 0)));
    }

    private static function string(array $metadata, string $key): ?string
    {
        return is_string($metadata[$key] ?? null) && $metadata[$key] !== '' ? $metadata[$key] : null;
    }
}
