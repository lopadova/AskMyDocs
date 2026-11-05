<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\MemoryBoundedMimeStructure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Part;
use Webklex\PHPIMAP\Structure;

final class MemoryBoundedMimeStructureTest extends TestCase
{
    public function test_nested_alternatives_and_attachments_match_the_existing_decoders(): void
    {
        $header = $this->header('multipart/mixed; boundary="outer"');
        $body = "--outer\r\nContent-Type: multipart/alternative; boundary=inner\r\n\r\n"
            ."--inner\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\ncaf=E9\r\n"
            ."--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<b>café</b>\r\n--inner--\r\n"
            ."--outer\r\nContent-Type: image/png; name=photo.png\r\nContent-Disposition: inline; filename=photo.png\r\nContent-ID: <photo-id>\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode("\x89PNG\0\1\2")."\r\n"
            ."--outer\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=utf-8\x27\x27report%20one.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-1.7 test')."\r\n"
            ."--outer\r\nContent-Type: message/rfc822\r\nContent-Disposition: attachment; filename=forwarded.eml\r\n\r\nSubject: forwarded\r\n\r\noriginal mail\r\n"
            ."--outer--\r\n";

        $old = new Structure($body, $header);
        $bounded = new MemoryBoundedMimeStructure($body, $header);
        $describe = static fn (Part $part): array => [
            hash('sha256', $part->content), $part->encoding, $part->content_type,
            $part->isAttachment(), $part->filename, $part->name,
            $part->charset, $part->disposition, $part->id,
        ];
        $this->assertCount(5, $bounded->parts);
        $this->assertSame(array_map($describe, $old->parts), array_map($describe, $bounded->parts));
    }

    public function test_preamble_epilogue_and_boundary_like_body_text_are_not_mime_parts(): void
    {
        $body = "preamble\r\n--bound\r\nContent-Type: text/plain\r\n\r\ntext --bound\r\n--bound-more\r\nstill text\r\n--bound--\r\nepilogue";
        $parsed = new MemoryBoundedMimeStructure($body, $this->header('multipart/mixed; boundary=bound'));
        $this->assertCount(1, $parsed->parts);
        $this->assertSame("text --bound\r\n--bound-more\r\nstill text", $parsed->parts[0]->content);
    }

    public function test_empty_part_headers_and_empty_bodies_are_preserved(): void
    {
        $parsed = new MemoryBoundedMimeStructure("--bound\r\n\r\nbody\r\n--bound\r\nContent-Type: text/plain\r\n\r\n\r\n--bound--", $this->header('multipart/mixed; boundary=bound'));
        $this->assertCount(2, $parsed->parts);
        $this->assertSame('body', $parsed->parts[0]->content);
        $this->assertSame('', $parsed->parts[1]->content);
    }

    public function test_missing_final_boundary_fails_instead_of_omitting_an_attachment(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no closing MIME boundary');
        new MemoryBoundedMimeStructure("--bound\r\nContent-Type: application/pdf\r\n\r\nattachment bytes", $this->header('multipart/mixed; boundary=bound'));
    }

    private function header(string $type): Header
    {
        return new Header('Content-Type: '.$type."\r\n", (new ClientManager)->getConfig());
    }
}
