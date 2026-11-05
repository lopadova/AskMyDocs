<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use ReflectionClass;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/** Retain Webklex's header/charset/attachment decoders with bounded MIME splitting. */
final class MemoryBoundedImapMessage extends Message
{
    public const RAW_SIZE_THRESHOLD = 8 * 1024 * 1024;

    /** Consume the downloaded body, releasing it before attachment decoding. */
    public static function fromRaw(Client $client, int $uid, string $headers, string &$body, array $flags): self
    {
        // Mirror Message::make's public factory lifecycle. Its factory uses
        // `self`, so inheriting it would instantiate the unbounded base parser.
        $message = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $message->boot($client->getConfig());
        $mask = $client->getDefaultMessageMask();
        if ($mask !== null) {
            $message->setMask($mask);
        }
        $message->setEvents(['message' => $client->getDefaultEvents('message'), 'flag' => $client->getDefaultEvents('flag')]);
        $message->setFolderPath($client->getFolderPath());
        $message->setSequence(IMAP::ST_UID);
        $message->setFetchOption(IMAP::FT_PEEK);
        $message->setClient($client);
        $message->setSequenceId($uid, null);
        $message->parseRawHeader($headers);
        $message->parseRawFlags($flags);
        $message->structure = new MemoryBoundedMimeStructure($body, $message->header);
        $body = '';
        $message->decodeParts();
        $message->peek();

        return $message;
    }

    public function parseRawBody(string $raw_body): Message
    {
        $this->structure = new MemoryBoundedMimeStructure($raw_body, $this->header);
        unset($raw_body);
        $this->decodeParts();

        return $this;
    }

    private function decodeParts(): void
    {
        $this->getClient()?->openFolder($this->folder_path);
        foreach ($this->structure->parts as $part) {
            if ($part->isAttachment()) {
                $this->fetchAttachment($part);
            } else {
                $encoding = $this->decoder->getEncoding($part);
                $content = $this->decoder->decode($part->content, (string) $part->encoding);
                if ($encoding !== 'us-ascii') {
                    $content = $this->decoder->convertEncoding($content, $encoding);
                }
                $this->addBody($part->subtype ?? '', $content);
            }
        }
    }
}
