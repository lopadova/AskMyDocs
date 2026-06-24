<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Database\Seeders\TestEmailFixtures;

/**
 * Risolve la selezione di caselle condivisa dai comandi dell'harness
 * (`mail:seed-imap`, `connector:imap:install`):
 *   - `--all`               → tutte le caselle del fixture;
 *   - `--mailbox=<key>`     → quelle caselle (ripetibile);
 *   - `--project=<key>`     → tutte le caselle dell'azienda (ripetibile).
 * I tre input si sommano; il risultato è de-duplicato preservando l'ordine.
 */
final class MailboxSelection
{
    /**
     * @param  list<string>|array<int,string>  $mailboxes
     * @param  list<string>|array<int,string>  $projects
     * @return list<string>
     */
    public static function resolve(bool $all, array $mailboxes, array $projects): array
    {
        if ($all) {
            return TestEmailFixtures::mailboxKeys();
        }

        $keys = [];

        foreach ($mailboxes as $mailboxKey) {
            $mailboxKey = trim((string) $mailboxKey);
            if ($mailboxKey !== '') {
                $keys[$mailboxKey] = true;
            }
        }

        foreach ($projects as $projectKey) {
            $projectKey = trim((string) $projectKey);
            if ($projectKey === '') {
                continue;
            }
            foreach (TestEmailFixtures::mailboxKeysForProject($projectKey) as $mailboxKey) {
                $keys[$mailboxKey] = true;
            }
        }

        return array_keys($keys);
    }
}
