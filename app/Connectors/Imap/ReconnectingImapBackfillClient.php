<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/** Reconnect-on-transient-drop decorator for the host bulk backfill client. */
final class ReconnectingImapBackfillClient implements ImapBackfillClient
{
    /** @var list<string> */
    private const TRANSIENT_NEEDLES = [
        'broken pipe', 'connection reset', 'connection closed', 'connection failed',
        'connection setup failed', 'empty response', 'no response', 'not connected',
        'stream', 'ssl', 'timed out', 'timeout', 'eof',
    ];

    private const MAX_ATTEMPTS_CEILING = 10;

    public function __construct(
        private readonly ImapBackfillClient $inner,
        private readonly int $maxAttempts,
        private readonly int $retryDelayMs,
    ) {}

    public function mailboxes(): array
    {
        return $this->attempt('backfill.mailboxes', fn (): array => $this->inner->mailboxes());
    }

    public function selectMailbox(string $mailbox): MailboxState
    {
        return $this->attempt('backfill.selectMailbox', fn (): MailboxState => $this->inner->selectMailbox($mailbox));
    }

    public function allUids(string $mailbox): array
    {
        return $this->attempt('backfill.allUids', fn (): array => $this->inner->allUids($mailbox));
    }

    public function uidsBetween(
        string $mailbox,
        Carbon $start,
        Carbon $end,
        int $afterUid = 0,
        ?int $throughUid = null,
        ?int $limit = null,
    ): array {
        return $this->attempt(
            'backfill.uidsBetween',
            fn (): array => $this->inner->uidsBetween($mailbox, $start, $end, $afterUid, $throughUid, $limit),
        );
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        return $this->attempt('backfill.fetchMessage', fn (): ImapMessage => $this->inner->fetchMessage($mailbox, $uid));
    }

    public function fetchMessages(string $mailbox, array $uids): array
    {
        return $this->attempt('backfill.fetchMessages', fn (): array => $this->inner->fetchMessages($mailbox, $uids));
    }

    public function close(): void
    {
        $this->inner->close();
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function attempt(string $label, callable $operation): mixed
    {
        $maxAttempts = min(self::MAX_ATTEMPTS_CEILING, max(1, $this->maxAttempts));
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $operation();
            } catch (ConnectorAuthException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts || ! $this->isTransientDrop($e)) {
                    throw $e;
                }

                Log::warning('[connector-imap] transient IMAP backfill drop — reconnecting and retrying', [
                    'operation' => $label,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                try {
                    $this->inner->close();
                } catch (\Throwable) {
                    // The socket is already gone; the next call reconnects lazily.
                }
                $delayMs = max(0, $this->retryDelayMs);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
    }

    private function isTransientDrop(\Throwable $e): bool
    {
        return ! $e instanceof \Error
            && Str::contains($e->getMessage(), self::TRANSIENT_NEEDLES, ignoreCase: true);
    }
}
