<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors;

use App\Connectors\Imap\ReadOnlyImapProtocol;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Connection\Protocols\Response;

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

    public function test_large_multiline_literal_is_complete_without_retaining_a_second_wire_copy(): void
    {
        $body = str_repeat("base64-line-of-private-attachment\r\n", 100_000);
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $stream = fopen('php://temp', 'w+');
        $protocol->stream = $stream;
        $response = Response::empty();
        $tokens = [];
        try {
            fwrite($stream, '* 1 FETCH (UID 7 BODY[TEXT] {'.strlen($body)."}\r\n".$body.")\r\nTAG1 OK FETCH completed\r\n");
            rewind($stream);
            $protocol->readLine($response, $tokens);

            $this->assertSame(hash('sha256', $body), hash('sha256', $tokens[2][3]));
            $this->assertLessThan(150, strlen(implode('', $response->getResponse())));
            $this->assertStringNotContainsString('private-attachment', implode('', $response->getResponse()));
            $this->assertTrue($protocol->readLine($response, $tokens, 'TAG1'));
            $this->assertSame(['OK', 'FETCH', 'completed'], $tokens);
        } finally {
            $protocol->stream = false;
            fclose($stream);
        }
    }

    public function test_counted_literals_preserve_binary_data_and_the_next_response(): void
    {
        $body = "a\0b\r\nc\n) UID 999"; // Delimiters inside a literal are opaque bytes.
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $stream = fopen('php://temp', 'w+');
        $protocol->stream = $stream;
        $response = Response::empty();
        $tokens = [];
        try {
            fwrite($stream, "* 1 FETCH (UID 7 BODY[HEADER] {0}\r\n BODY[TEXT] {".strlen($body)."}\r\n".$body." FLAGS (\\Seen))\r\nTAG1 OK done\r\n");
            rewind($stream);
            $protocol->readLine($response, $tokens);
            $this->assertSame(['UID', '7', 'BODY[HEADER]', '', 'BODY[TEXT]', $body, 'FLAGS', ['\\Seen']], $tokens[2]);
            $this->assertTrue($protocol->readLine($response, $tokens, 'TAG1'));
            $this->assertSame(['OK', 'done'], $tokens);
        } finally {
            $protocol->stream = false;
            fclose($stream);
        }
    }

    public function test_truncated_literal_fails_without_synthesizing_missing_bytes(): void
    {
        $protocol = new ReadOnlyImapProtocol((new ClientManager)->getConfig());
        $stream = fopen('php://temp', 'w+');
        $protocol->stream = $stream;
        try {
            fwrite($stream, "* 1 FETCH (UID 7 BODY[TEXT] {20}\r\nshort");
            rewind($stream);
            $tokens = [];
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('IMAP literal ended before its declared byte count.');
            $protocol->readLine(Response::empty(), $tokens);
        } finally {
            $protocol->stream = false;
            fclose($stream);
        }
    }
}
