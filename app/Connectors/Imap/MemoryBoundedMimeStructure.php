<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use RuntimeException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Part;
use Webklex\PHPIMAP\Structure;

/** Split MIME by offsets: never explode or repeatedly copy a large multipart body. */
final class MemoryBoundedMimeStructure extends Structure
{
    public function __construct(string $body, private readonly Header $mimeHeader)
    {
        parent::__construct($body, $mimeHeader);
    }

    public function find_parts(): array
    {
        $parts = [];
        $this->collect(0, strlen($this->raw), $this->mimeHeader, $parts, 0);
        // Each leaf now owns its encoded bytes; the complete wire body is no
        // longer needed during decoding (which allocates another large string).
        $this->raw = '';

        return $parts;
    }

    /** @param list<Part> $parts */
    private function collect(int $start, int $end, Header $header, array &$parts, int $depth): void
    {
        if ($depth > 50) {
            throw new RuntimeException('IMAP MIME nesting exceeds 50 levels.');
        }
        $type = strtolower((string) $header->get('content_type')->first());
        if (! str_starts_with($type, 'multipart')) {
            // Part::parse trims both ends. Trim by offsets first so it does not
            // keep both a large raw part and a second trimmed encoded copy.
            while ($start < $end && str_contains(" \t\n\r\0\x0B", $this->raw[$start])) {
                $start++;
            }
            while ($end > $start && str_contains(" \t\n\r\0\x0B", $this->raw[$end - 1])) {
                $end--;
            }
            $parts[] = new Part(substr($this->raw, $start, $end - $start), $header->getConfig(), $header, count($parts));

            return;
        }
        $boundary = $header->getBoundary();
        if ($boundary === null || $boundary === '') {
            throw new RuntimeException('IMAP multipart message has no MIME boundary.');
        }
        $delimiter = '--'.$boundary;
        $partStart = null;
        $cursor = $start;
        while (($position = strpos($this->raw, $delimiter, $cursor)) !== false && $position < $end) {
            $cursor = $position + strlen($delimiter);
            if ($cursor > $end) {
                break;
            }
            // A boundary delimiter must occupy a line, not occur inside text or
            // merely prefix another boundary in a nested multipart message.
            if ($position !== $start && $this->raw[$position - 1] !== "\n") {
                continue;
            }
            $closing = $cursor + 2 <= $end && substr($this->raw, $cursor, 2) === '--';
            $lineEnd = strpos($this->raw, "\n", $cursor);
            $lineEnd = $lineEnd === false ? $end : min($lineEnd, $end);
            if (trim(substr($this->raw, $cursor + ($closing ? 2 : 0), $lineEnd - $cursor - ($closing ? 2 : 0)), " \t\r") !== '') {
                continue;
            }
            if ($partStart !== null) {
                $partEnd = $position;
                if (substr($this->raw, $partEnd - 2, 2) === "\r\n") {
                    $partEnd -= 2;
                } elseif ($partEnd > $partStart && $this->raw[$partEnd - 1] === "\n") {
                    $partEnd--;
                }
                $this->collectPart($partStart, $partEnd, $header, $parts, $depth + 1);
            }
            if ($closing) {
                return; // MIME epilogue is outside every part.
            }
            $partStart = min($end, $lineEnd + 1);
            $cursor = $partStart;
        }
        if ($partStart !== null) {
            // An interrupted or malformed multipart must never confirm a UID
            // while silently losing its final attachment.
            throw new RuntimeException('IMAP multipart message has no closing MIME boundary.');
        }
        if ($start !== $end) {
            throw new RuntimeException('IMAP multipart message contains no MIME parts.');
        }
    }

    /** @param list<Part> $parts */
    private function collectPart(int $start, int $end, Header $parent, array &$parts, int $depth): void
    {
        if (substr($this->raw, $start, 2) === "\r\n") {
            $separator = $start;
            $length = 2;
        } elseif (($this->raw[$start] ?? '') === "\n") {
            $separator = $start;
            $length = 1;
        } else {
            $separator = strpos($this->raw, "\r\n\r\n", $start);
            $length = 4;
            if ($separator === false || $separator >= $end) {
                $separator = strpos($this->raw, "\n\n", $start);
                $length = 2;
            }
        }
        if ($separator === false || $separator + $length > $end) {
            throw new RuntimeException('IMAP MIME part is missing its header separator.');
        }
        // Preserve the final header CRLF used by Webklex's attachment IDs.
        $header = new Header(substr($this->raw, $start, $separator - $start + ($length === 4 ? 2 : 0)), $parent->getConfig());
        $this->collect($separator + $length, $end, $header, $parts, $depth);
    }
}
