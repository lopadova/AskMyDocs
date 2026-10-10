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

    public function test_noop_seen_restore_reuses_only_its_immediate_flag_read(): void
    {
        [$clientStream, $serverStream] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $protocol->stream = $clientStream;
        try {
            fwrite($serverStream, "* 1 FETCH (UID 7 FLAGS ())\r\n* 2 FETCH (UID 8 FLAGS (\\Seen \\Flagged))\r\nTAG1 OK FETCH completed\r\n");
            $flags = $protocol->flags([7, 8])->validatedData();
            $this->assertSame("TAG1 UID FETCH 7,8 (FLAGS)\r\n", fgets($serverStream));
            $protocol->store(['\\Seen'], 7, 7, '-')->validatedData();
            $this->assertSame([7 => $flags[7]], $protocol->flags([7])->validatedData());
            stream_set_blocking($serverStream, false);
            $this->assertSame('', fread($serverStream, 1024));
            stream_set_blocking($serverStream, true);

            // A normal later read must reach the server and observe new flags.
            fwrite($serverStream, "* 1 FETCH (UID 7 FLAGS (\\Seen))\r\nTAG2 OK FETCH completed\r\n");
            $this->assertSame([7 => ['\\Seen']], $protocol->flags([7])->validatedData());
            $this->assertSame("TAG2 UID FETCH 7:7 (FLAGS)\r\n", fgets($serverStream));

            // The same UID in another folder must never reuse a prior folder's flags.
            $protocol->sendRequest('EXAMINE', ['"Other"']);
            $this->assertSame("TAG3 EXAMINE \"Other\"\r\n", fgets($serverStream));
            $protocol->store(['\\Seen'], 7, 7, '-')->validatedData();
            fwrite($serverStream, "* 1 FETCH (UID 7 FLAGS ())\r\nTAG4 OK FETCH completed\r\n");
            $this->assertSame([7 => []], $protocol->flags([7])->validatedData());
            $this->assertSame("TAG4 UID FETCH 7:7 (FLAGS)\r\n", fgets($serverStream));
        } finally {
            $protocol->stream = false;
            fclose($clientStream);
            fclose($serverStream);
        }
    }

    public function test_recent_wire_reads_avoid_repeated_health_check_commands(): void
    {
        [$clientStream, $serverStream] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $protocol->stream = $clientStream;
        try {
            fwrite($serverStream, "TAG1 OK NOOP completed\r\n");
            $this->assertTrue($protocol->connected());
            $this->assertSame("TAG1 NOOP\r\n", fgets($serverStream));
            $this->assertTrue($protocol->connected());
            stream_set_blocking($serverStream, false);
            $this->assertSame('', fread($serverStream, 1024));
            stream_set_blocking($serverStream, true);

            // After idle time, perform a fresh server check.
            (new \ReflectionProperty($protocol, 'lastWireActivityAt'))->setValue($protocol, microtime(true) - 16);
            fwrite($serverStream, "TAG2 OK NOOP completed\r\n");
            $this->assertTrue($protocol->connected());
            $this->assertSame("TAG2 NOOP\r\n", fgets($serverStream));
        } finally {
            $protocol->stream = false;
            fclose($clientStream);
            fclose($serverStream);
        }
    }
}
