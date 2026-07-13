<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/**
 * Decorates an {@see ImapClientInterface} so a TRANSIENT transport drop on any
 * connection-triggering call — the classic Gmail/Exchange "fwrite(): SSL: Broken
 * pipe" (or "connection reset"/"empty response"/idle drop) that surfaces when the
 * server closes a live IMAP session mid-flight — is absorbed by dropping the dead
 * socket and retrying the SAME operation on a fresh connection, instead of bubbling
 * up as a fatal sync failure.
 *
 * WHY host-side and not in the connector: the package's {@see \Padosoft\AskMyDocsConnectorImap\ImapConnector::runSync()}
 * only guards transport drops around the per-folder scan — the INITIAL
 * `listMailboxes()` enumeration, the reconcile `searchUids()` scan, and the
 * per-message fetch cascade are unguarded, so a single drop on any of them aborts the
 * whole run (installation stuck "Not synced yet"). Wrapping every client the factory
 * produces fixes all three paths at once without touching the vendored package.
 *
 * Composition order matters: this decorator sits INSIDE {@see SerializingImapClient}
 * (the per-mailbox lock), so the close-and-reconnect happens UNDER the held lock —
 * the retry reconnects to the SAME mailbox we already own, never opening a second
 * simultaneous connection and never releasing the cross-tenant serialization lock
 * between the drop and the retry. The inner `close()` here is the raw client's socket
 * teardown (no lock), which flips it back to "disconnected" so the next call
 * re-connects lazily.
 *
 * Never retries a {@see ConnectorAuthException}: rejected credentials are permanent
 * (the host must re-prompt), so they propagate immediately. Only errors whose message
 * matches the transient-drop signature are retried; anything else (a real
 * "Mailbox not found", a `\TypeError`, "Too many simultaneous connections") propagates
 * on the first throw so the job-level retry/backoff — or the operator — handles it.
 */
final class ReconnectingImapClient implements ImapClientInterface
{
    /**
     * How a dropped IMAP session actually surfaces across webklex + PHP stream
     * errors. Kept in lockstep with the connector's own per-folder guard so a drop
     * is classified identically wherever it lands. Matched case-insensitively as a
     * substring of the exception message.
     *
     * @var list<string>
     */
    private const TRANSIENT_NEEDLES = [
        'broken pipe',
        'connection reset',
        'connection closed',
        'connection failed',
        'connection setup failed',
        'empty response',
        'no response',
        'not connected',
        'stream',
        'ssl',
        'timed out',
        'timeout',
        'eof',
    ];

    /**
     * Hard ceiling on the per-operation attempt budget, regardless of config. The
     * reconnect runs inside the cross-tenant per-mailbox lock, so an unbounded budget
     * must never let a misconfiguration hold that lock past its TTL. 10 attempts ×
     * a sane retry delay stays well under CONNECTOR_IMAP_MAILBOX_LOCK_TTL (700s).
     */
    private const MAX_ATTEMPTS_CEILING = 10;

    public function __construct(
        private readonly ImapClientInterface $inner,
        private readonly int $maxAttempts,
        private readonly int $retryDelayMs,
    ) {}

    /** @return list<string> */
    public function listMailboxes(): array
    {
        return $this->attempt('listMailboxes', fn (): array => $this->inner->listMailboxes());
    }

    public function selectMailbox(string $name): MailboxState
    {
        return $this->attempt('selectMailbox', fn (): MailboxState => $this->inner->selectMailbox($name));
    }

    /** @return list<int> */
    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        return $this->attempt('searchUids', fn (): array => $this->inner->searchUids($mailbox, $since, $sinceUid));
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        return $this->attempt('fetchMessage', fn (): ImapMessage => $this->inner->fetchMessage($mailbox, $uid));
    }

    public function ping(): bool
    {
        return $this->attempt('ping', fn (): bool => $this->inner->ping());
    }

    /**
     * Teardown is best-effort and idempotent (the raw client already swallows a
     * LOGOUT write to a dead socket) — never retried: there is nothing to recover,
     * and a throw here must not mask a caller's real error.
     */
    public function close(): void
    {
        $this->inner->close();
    }

    /**
     * Run $operation, and on a transient transport drop drop the dead connection and
     * retry it on a fresh one, up to maxAttempts total tries.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function attempt(string $label, callable $operation): mixed
    {
        // Clamp BOTH ends: a 0/negative budget would never run (floor at 1 = try
        // once, no retry), and — because the retry runs INSIDE the held per-mailbox
        // lock — an unbounded budget from a misconfiguration could hold that
        // cross-tenant lock for `(budget − 1) × retry_delay_ms`, potentially past the
        // lock TTL, at which point a waiting tenant could open a second connection to
        // the same mailbox (the very thing serialization prevents). A small ceiling
        // keeps the worst case negligible against CONNECTOR_IMAP_MAILBOX_LOCK_TTL.
        $maxAttempts = min(self::MAX_ATTEMPTS_CEILING, max(1, $this->maxAttempts));
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $operation();
            } catch (ConnectorAuthException $e) {
                // Permanent: rejected credentials never recover on a reconnect.
                throw $e;
            } catch (\Throwable $e) {
                if ($attempt >= $maxAttempts || ! $this->isTransientDrop($e)) {
                    throw $e;
                }

                $this->reconnect($label, $attempt, $e);
            }
        }
    }

    /**
     * Drop the dead socket so the next call re-connects lazily, pausing briefly so a
     * server that just cut us is given a beat before we knock again.
     */
    private function reconnect(string $label, int $attempt, \Throwable $cause): void
    {
        Log::warning('[connector-imap] transient IMAP drop — reconnecting and retrying', [
            'operation' => $label,
            'attempt' => $attempt,
            'error' => $cause->getMessage(),
        ]);

        try {
            $this->inner->close();
        } catch (\Throwable) {
            // Socket already gone — the next call reconnects regardless.
        }

        if ($this->retryDelayMs > 0) {
            usleep($this->retryDelayMs * 1000);
        }
    }

    private function isTransientDrop(\Throwable $e): bool
    {
        // A programming fault (\Error: TypeError, etc.) is never a transport drop —
        // its message won't match the needles anyway, but bail explicitly so a bug
        // can never masquerade as a recoverable outage and get silently retried.
        if ($e instanceof \Error) {
            return false;
        }

        return Str::contains($e->getMessage(), self::TRANSIENT_NEEDLES, ignoreCase: true);
    }
}
