<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\EmailMessageBuilder;
use App\Services\Demo\MailboxTarget;
use Database\Seeders\TestEmailFixtures;
use Tests\TestCase;

/**
 * Unit del builder RFC822 (parte pura, senza IMAP). Verifica che il messaggio
 * porti il destinatario reale, il mittente, la data e l'header di seeding —
 * i contratti su cui poggiano ingest (To) e --purge (header).
 */
final class EmailMessageBuilderTest extends TestCase
{
    private function target(): MailboxTarget
    {
        return new MailboxTarget(
            mailboxKey: 'rotta-logistics-1',
            projectKey: 'rotta-logistics',
            companyName: 'Rotta Sicura Logistics',
            email: 'rotta.test1.askmydocs@gmail.com',
            host: 'imap.gmail.com',
            port: 993,
            encryption: 'ssl',
            validateCert: true,
            secret: 'irrelevant-for-build',
            folder: 'INBOX',
        );
    }

    public function test_builds_rfc822_with_recipient_sender_date_and_seed_header(): void
    {
        $fixture = [
            'subject' => 'Conferma spedizione RL-2024-0815',
            'from_name' => 'Sistema OrbitaWMS',
            'from_email' => 'noreply@orbitawms.example.com',
            'date' => '2024-03-15 09:30:00',
            'body_text' => "Spedizione RL-2024-0815 registrata.\nTracking: RL-TRACK-8842.",
        ];

        $raw = (new EmailMessageBuilder)->build($this->target(), $fixture);

        // To = casella reale (l'ingest attribuisce il messaggio al destinatario).
        $this->assertStringContainsString('To: rotta.test1.askmydocs@gmail.com', $raw);
        // From conserva l'indirizzo del mittente (ASCII, non codificato).
        $this->assertStringContainsString('noreply@orbitawms.example.com', $raw);
        // Header di seeding (mailbox_key) usato da --purge.
        $this->assertStringContainsString(TestEmailFixtures::SEED_HEADER.': rotta-logistics-1', $raw);
        // Date header = data narrativa della fixture (anno 2024 presente).
        $this->assertMatchesRegularExpression('/^Date: .*2024/m', $raw);
        // Il "fatto-esca" sopravvive alla codifica del corpo (ASCII).
        $this->assertStringContainsString('RL-2024-0815', $raw);
    }

    public function test_seed_header_matches_the_target_project_key(): void
    {
        $fixture = [
            'subject' => 'Test',
            'from_name' => 'X',
            'from_email' => 'x@example.com',
            'date' => '2024-01-01 00:00:00',
            'body_text' => 'corpo',
        ];

        $target = new MailboxTarget(
            mailboxKey: 'prometeo-antincendio-2',
            projectKey: 'prometeo-antincendio',
            companyName: 'Prometeo',
            email: 'prometeo.test2.askmydocs@gmail.com',
            host: 'imap.gmail.com',
            port: 993,
            encryption: 'ssl',
            validateCert: true,
            secret: '',
            folder: 'INBOX',
        );

        $raw = (new EmailMessageBuilder)->build($target, $fixture);

        // Header = mailbox_key (purge mailbox-scoped); To = indirizzo della casella.
        $this->assertStringContainsString(TestEmailFixtures::SEED_HEADER.': prometeo-antincendio-2', $raw);
        $this->assertStringContainsString('To: prometeo.test2.askmydocs@gmail.com', $raw);
    }
}
