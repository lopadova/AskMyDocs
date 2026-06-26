<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Computes the stable lock key that identifies a physical IMAP mailbox (account),
 * used to serialize every connection to it — across surfaces AND across tenants.
 *
 * The mailbox identity is the (host, port, username) triple: two installations
 * that point at the same account share the server's per-account connection limit
 * even when they belong to DIFFERENT tenants or sync DIFFERENT folders/labels.
 * The key therefore deliberately OMITS `tenant_id` and the folder set — it is a
 * shared physical resource, the one case where cross-tenant scoping (R30) does not
 * apply because we are protecting the resource, not isolating tenant data.
 *
 * Normalisation: host + username lowercased + trimmed (case-insensitive in
 * practice), the port included (a default 993 is materialised so an omitted port
 * collides with an explicit 993). The triple is hashed so the key is a fixed,
 * driver-safe Redis token that does not leak the mailbox address into lock names.
 */
final class MailboxLockKey
{
    private const PREFIX = 'imap-mailbox:';

    private const DEFAULT_PORT = 993;

    /**
     * @param  array<string,mixed>  $connection  The `config_json.connection` block.
     * @return string|null  null when host/username are absent (nothing meaningful
     *                       to serialize on — the caller skips locking).
     */
    public static function forConnection(array $connection): ?string
    {
        $host = strtolower(trim((string) ($connection['host'] ?? '')));
        $username = strtolower(trim((string) ($connection['username'] ?? '')));

        if ($host === '' || $username === '') {
            return null;
        }

        $port = (int) ($connection['port'] ?? self::DEFAULT_PORT);

        return self::PREFIX.sha1($host.':'.$port.':'.$username);
    }

    /**
     * The key for an installation, read from its `config_json.connection`.
     */
    public static function forInstallation(?ConnectorInstallation $installation): ?string
    {
        if ($installation === null) {
            return null;
        }

        $config = (array) ($installation->config_json ?? []);

        return self::forConnection((array) ($config['connection'] ?? []));
    }
}
