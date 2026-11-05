<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\EmailMessageBuilder;
use App\Services\Demo\MailboxTarget;
use Carbon\Carbon;
use Database\Seeders\TestEmailFixtures;
use Tests\TestCase;

/**
 * Unit del builder RFC822 (parte pura, senza IMAP). Verifica che il messaggio
 * porti il destinatario reale, il mittente, la data e l'header di seeding —
 * i contratti su cui poggiano ingest (To) e --purge (header).
 */
final class EmailMessageBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        // Il layer gold legacy resta fuori dal namespace riservato che abilita
        // il gate AI delle fixture generate v2.
        $this->assertStringContainsString('@gold-fixtures.askmydocs.invalid>', $raw);
    }

    public function test_seed_header_matches_the_target_mailbox_key(): void
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

    public function test_schema_v2_message_is_deterministic_and_preserves_thread_headers(): void
    {
        Carbon::setTestNow('2026-07-23 12:34:56 UTC');
        $fixtureId = str_repeat('a', 64);
        $parentId = '<large-v1.'.str_repeat('b', 64).'@fixtures.askmydocs.invalid>';
        $fixture = [
            'dataset_version' => 'large-v1',
            'fixture_id' => $fixtureId,
            'message_id' => '<large-v1.'.$fixtureId.'@fixtures.askmydocs.invalid>',
            'in_reply_to' => $parentId,
            'references' => [$parentId],
            'scenario_type' => 'shipment-delay',
            'subject' => 'Re: ritardo spedizione RL-2042',
            'from_name' => 'Mario Rossi',
            'from_email' => 'mario.rossi@example.com',
            'to' => ['operations@rotta.example.com'],
            'cc' => ['support@rotta.example.com'],
            'date' => '2026-05-02 10:30:00',
            'internal_date' => '2026-05-02T10:31:00+00:00',
            'body_text' => 'Confermo il ritardo della spedizione RL-2042.',
            'headers' => ['Precedence' => 'bulk'],
            'attachments' => [],
        ];

        $builder = new EmailMessageBuilder;
        $first = $builder->prepare($this->target(), $fixture, 42);
        $second = $builder->prepare($this->target(), $fixture, 42);

        $this->assertSame($first->raw, $second->raw);
        $this->assertSame('<large-v1.'.$fixtureId.'@fixtures.askmydocs.invalid>', $first->messageId);
        $this->assertSame(42, $first->sequence);
        $this->assertSame(
            '2026-07-23T12:34:56+00:00',
            $first->internalDate->format(DATE_ATOM),
            'IMAP arrival time must be current even when the narrative fixture date is historical.',
        );
        $this->assertSame(
            '23-Jul-2026 12:34:56 +0000',
            $first->imapInternalDate(),
            'Webklex must receive an RFC 2060 string instead of DateTimeImmutable.',
        );
        $this->assertStringContainsString('Message-ID: <large-v1.'.$fixtureId.'@fixtures.askmydocs.invalid>', $first->raw);
        $this->assertStringContainsString('In-Reply-To: '.$parentId, $first->raw);
        $this->assertStringContainsString('References: '.$parentId, $first->raw);
        $this->assertStringContainsString('X-AskMyDocs-Dataset-Version: large-v1', $first->raw);
        $this->assertStringContainsString('X-AskMyDocs-Fixture-Id: '.$fixtureId, $first->raw);
        $this->assertStringContainsString('X-AskMyDocs-Scenario: shipment-delay', $first->raw);
    }

    public function test_rejects_header_injection_from_dataset_records(): void
    {
        $fixtureId = str_repeat('c', 64);
        $fixture = [
            'dataset_version' => 'large-v1',
            'fixture_id' => $fixtureId,
            'message_id' => '<large-v1.'.$fixtureId.'@fixtures.askmydocs.invalid>',
            'subject' => 'Test',
            'from_name' => 'X',
            'from_email' => 'x@example.com',
            'date' => '2026-01-01 00:00:00',
            'body_text' => 'corpo',
            'headers' => ['X-AskMyDocs-Trace' => "ok\r\nBcc: victim@example.com"],
        ];

        $this->expectException(\InvalidArgumentException::class);

        (new EmailMessageBuilder)->prepare($this->target(), $fixture, 1);
    }
}
