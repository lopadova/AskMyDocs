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
    /** @var array<int,array> Only the flags from the last explicit read. */
    private array $lastFlags = [];

    private int|string $lastFlagsSequence = IMAP::ST_UID;

    private ?int $acknowledgedSeenRestore = null;

    private float $lastWireActivityAt = 0;

    public function connected(): bool
    {
        if (! is_resource($this->stream) || feof($this->stream)) {
            return false;
        }
        // Webklex checks the connection while parsing every MIME message and
        // attachment. Recent successful wire reads already prove it is alive;
        // only an idle connection needs another NOOP. A failed read clears this
        // shortcut and the existing reconnect decorator retries the operation.
        if (microtime(true) - $this->lastWireActivityAt < 15) {
            return true;
        }

        return parent::connected();
    }

    public function readLine(Response $response, array|string &$tokens = [], string $wantedTag = '*', bool $dontParse = false): bool
    {
        try {
            $complete = parent::readLine($response, $tokens, $wantedTag, $dontParse);
            $this->lastWireActivityAt = microtime(true);

            return $complete;
        } catch (\Throwable $exception) {
            $this->lastWireActivityAt = 0;

            throw $exception;
        }
    }

    public function flags(int|array $uids, int|string $uid = IMAP::ST_UID): Response
    {
        $restoreUid = $this->acknowledgedSeenRestore;
        $this->acknowledgedSeenRestore = null;
        $requested = is_array($uids) ? array_values($uids) : [$uids];
        // unsetFlag(Seen) immediately re-reads flags after its local no-op STORE.
        // Return the flags already fetched for this message instead of issuing
        // one redundant network command per unread message in a bulk fetch.
        if ($restoreUid !== null && $requested === [$restoreUid]
            && $uid === $this->lastFlagsSequence && isset($this->lastFlags[$restoreUid])) {
            return Response::empty()->setResult([$restoreUid => $this->lastFlags[$restoreUid]]);
        }

        $response = parent::flags($uids, $uid);
        $this->lastFlags = $response->successful() ? $response->array() : [];
        $this->lastFlagsSequence = $uid;

        return $response;
    }

    public function sendRequest(string $command, array $tokens = [], ?string &$tag = null): Response
    {
        $this->acknowledgedSeenRestore = null;
        $command = strtoupper(trim($command));
        if ($command === 'SELECT') {
            $command = 'EXAMINE';
        }
        if ($command === 'EXAMINE') {
            $this->lastFlags = [];
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
            $this->acknowledgedSeenRestore = ($to === null || $to === $from)
                && $uid === $this->lastFlagsSequence ? $from : null;

            return Response::empty()->setResult(true);
        }

        throw new RuntimeException('Read-only IMAP rejected a flag change.');
    }
}
