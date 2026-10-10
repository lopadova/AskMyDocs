<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

/** Keep host diagnostics and locks aligned with the connector's live endpoint. */
final class ImapConnectionParameters
{
    /** @param array<string,mixed> $config @return array<string,mixed> */
    public static function forConfig(array $config): array
    {
        $connection = (array) ($config['connection'] ?? []);

        // The app-only form stores only a username. Always override stale basic
        // endpoints too, so a Microsoft bearer token stays on Exchange Online.
        if (($config['auth_mode'] ?? 'basic') === 'xoauth2_client_credentials') {
            $provider = (array) config('connectors.providers.imap.client_credentials.microsoft', []);
            $connection['host'] = (string) ($provider['imap_host'] ?? 'outlook.office365.com');
            $connection['port'] = (int) ($provider['imap_port'] ?? 993);
            $connection['encryption'] = (string) ($provider['imap_encryption'] ?? 'ssl');
        }

        return $connection;
    }
}
