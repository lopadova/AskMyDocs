<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Carbon\Carbon;
use Database\Seeders\TestEmailFixtures;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Costruisce il messaggio RFC822 grezzo da una fixture e dalla casella target.
 *
 * Pure (nessun I/O) → è la parte unit-testabile del seeding: l'APPEND IMAP vero
 * vive in {@see WebklexMailboxAppender}. Punti chiave:
 *   - `To` = indirizzo REALE della casella target, così il messaggio è coerente
 *     una volta dentro la mailbox (e l'ingest lo attribuisce al destinatario).
 *   - header custom {@see TestEmailFixtures::SEED_HEADER} = project_key, usato dal
 *     `--purge` per ritrovare/eliminare solo i messaggi di test.
 *   - `Date:` = la data narrativa della fixture; la data di consegna IMAP
 *     (INTERNALDATE) la decide l'appender (now()) per restare dentro la finestra
 *     `date_window_days` del connettore anche con fixture datate nel passato.
 */
final class EmailMessageBuilder
{
    /**
     * @param  array{subject: string, from_name: string, from_email: string, body_text: string, date: string}  $fixture
     */
    public function build(MailboxTarget $target, array $fixture): string
    {
        $email = (new Email)
            ->from(new Address((string) $fixture['from_email'], (string) $fixture['from_name']))
            ->to($target->email)
            ->subject((string) $fixture['subject'])
            ->date(Carbon::parse((string) $fixture['date']))
            ->text((string) $fixture['body_text']);

        $email->getHeaders()->addTextHeader(TestEmailFixtures::SEED_HEADER, $target->projectKey);

        return $email->toString();
    }
}
