<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\MemoryBoundedImapMessage;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

final class MemoryBoundedImapMessageTest extends TestCase
{
    public function test_factory_preserves_decoded_bodies_headers_flags_and_attachment_identity(): void
    {
        $client = new MimeMessageTestClient((new ClientManager)->getConfig());
        $header = "Date: Sat, 10 Oct 2026 12:00:00 +0200\r\nFrom: Sender <sender@example.test>\r\n"
            ."To: recipient@example.test\r\nMessage-ID: <mime@example.test>\r\nSubject: =?UTF-8?B?Y2Fmw6k=?=\r\n"
            ."Content-Type: multipart/mixed; boundary=outer\r\n";
        $body = "--outer\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\ncaf=E9\r\n"
            ."--outer\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<b>café</b>\r\n"
            ."--outer\r\nContent-Type: application/pdf; name=report.pdf\r\nContent-Disposition: attachment; filename=report.pdf\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-1.7 attachment')."\r\n--outer--\r\n";
        $old = Message::make(42, null, $client, $header, $body, ['\\Seen', '\\Flagged'], IMAP::FT_PEEK, IMAP::ST_UID);
        $bounded = MemoryBoundedImapMessage::fromRaw($client, 42, $header, $body, ['\\Seen', '\\Flagged']);
        $describe = static fn (Message $message): array => [
            (int) $message->getUid(), (string) $message->getSubject(), (string) $message->getMessageId(),
            $message->getTextBody(), $message->getHTMLBody(), $message->getFlags()->all(),
            $message->getAttachments()->map(static fn ($attachment): array => [
                $attachment->getName(), $attachment->getContent(), $attachment->getId(), $attachment->getSize(),
            ])->all(),
        ];
        $this->assertSame($describe($old), $describe($bounded));
        $this->assertSame('café', $bounded->getTextBody());
        $this->assertCount(1, $bounded->getAttachments());
        $this->assertSame('%PDF-1.7 attachment', $bounded->getAttachments()->first()->getContent());
    }
}

final class MimeMessageTestClient extends Client
{
    public function getFolderPath(): string
    {
        return 'INBOX';
    }

    public function openFolder(string $folder_path, bool $force_select = false): array
    {
        return [];
    }

    public function disconnect(): Client
    {
        return $this;
    }
}
