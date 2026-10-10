<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use RuntimeException;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\IMAP;

/** Enforce inbound-only IMAP even when the MIME library tries to restore flags. */
final class ReadOnlyImapProtocol extends ImapProtocol
{
    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response
    {
        $command = strtoupper(trim($command));
        if ($command === 'SELECT') {
            $command = 'EXAMINE';
        }
        if (! in_array($command, [
            'EXAMINE', 'FETCH', 'UID FETCH', 'SEARCH', 'UID SEARCH', 'LIST', 'LSUB',
            'STATUS', 'CAPABILITY', 'NAMESPACE', 'NOOP', 'CHECK', 'ID', 'STARTTLS',
            'LOGIN', 'AUTHENTICATE', 'LOGOUT', 'GETQUOTA', 'GETQUOTAROOT',
        ], true)) {
            throw new RuntimeException("Read-only IMAP rejected {$command}.");
        }
        if (in_array($command, ['FETCH', 'UID FETCH'], true) && isset($tokens[1]) && is_string($tokens[1])) {
            // Keep the parser's expected BODY[...] response key; only the wire
            // request has PEEK, which the server omits from its response key.
            $tokens[1] = str_replace('BODY[', 'BODY.PEEK[', $tokens[1]);
        }

        return parent::sendRequest($command, $tokens, $tag);
    }

    public function content(int|array $uids, string $rfc = 'RFC822', int|string $uid = IMAP::ST_UID): Response
    {
        return $this->fetch(['BODY[TEXT]'], is_array($uids) ? $uids : [$uids], null, $uid);
    }

    public function store(
        array|string $flags, int $from, ?int $to = null, ?string $mode = null,
        bool $silent = true, int|string $uid = IMAP::ST_UID, ?string $item = null,
    ): Response {
        // Webklex's FT_PEEK calls unsetFlag(Seen) after parsing an unread body.
        // EXAMINE + BODY.PEEK already preserve the flags: acknowledge this one
        // library cleanup locally without sending any STORE to the server.
        if ($mode === '-' && $silent && $item === null
            && array_map(static fn ($flag): string => strtolower((string) $flag), (array) $flags) === ['\\seen']) {
            return Response::empty()->setResult(true);
        }

        throw new RuntimeException('Read-only IMAP rejected a flag change.');
    }
}
