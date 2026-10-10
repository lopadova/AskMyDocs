<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\ReadOnlyImapProtocol;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;

final class ReadOnlyImapProtocolTest extends TestCase
{
    public function test_selection_and_body_requests_are_read_only_on_the_wire(): void
    {
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $stream = fopen('php://temp', 'w+');
        $protocol->stream = $stream;
        try {
            $protocol->sendRequest('SELECT', ['"INBOX"']);
            $protocol->sendRequest('UID FETCH', ['7:7', '(BODY[TEXT])']);
            $protocol->store(['\\Seen'], 7, 7, '-')->validatedData();
            rewind($stream);
            $wire = stream_get_contents($stream);
            $this->assertStringContainsString('EXAMINE "INBOX"', $wire);
            $this->assertStringContainsString('UID FETCH 7:7 (BODY.PEEK[TEXT])', $wire);
            $this->assertStringNotContainsString('STORE', $wire);
            $this->assertStringNotContainsString('SELECT', $wire);

            foreach (['UID STORE', 'EXPUNGE', 'APPEND', 'UID COPY', 'UID MOVE', 'DELETE'] as $command) {
                try {
                    $protocol->sendRequest($command);
                    $this->fail("{$command} was allowed");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('Read-only IMAP rejected', $exception->getMessage());
                }
            }
            $this->expectException(RuntimeException::class);
            $protocol->store(['\\Deleted'], 7, 7, '+');
        } finally {
            $protocol->stream = false;
            fclose($stream);
        }
    }

    public function test_peek_response_is_parsed_using_the_server_body_key(): void
    {
        [$clientStream, $serverStream] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $protocol->stream = $clientStream;
        try {
            fwrite($serverStream, "* 1 FETCH (UID 7 BODY[TEXT] {5}\r\nhello)\r\nTAG1 OK FETCH completed\r\n");
            $this->assertSame([7 => 'hello'], $protocol->content([7])->validatedData());
            $this->assertSame("TAG1 UID FETCH 7:7 (BODY.PEEK[TEXT])\r\n", fgets($serverStream));
        } finally {
            $protocol->stream = false;
            fclose($clientStream);
            fclose($serverStream);
        }
    }
}
