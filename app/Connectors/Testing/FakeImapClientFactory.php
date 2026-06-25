<?php

declare(strict_types=1);

namespace App\Connectors\Testing;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

/**
 * v8.17 — a deterministic, OFFLINE IMAP client factory for E2E (and local) runs,
 * mirroring the `AI_PROVIDER=fake` seam: it lets the credential-connector flow be
 * exercised end-to-end without a real IMAP server (which is reached by the
 * BACKEND over TCP, so it cannot be stubbed by Playwright's `page.route`).
 *
 * Bound by {@see \App\Providers\AppServiceProvider} ONLY when
 * `config('connectors.fake_imap_ping')` is true (env `CONNECTOR_IMAP_FAKE_PING`,
 * default OFF) — production always uses the real package factory.
 *
 * The ping is INPUT-DRIVEN so both the happy and failure E2E paths are
 * deterministic from the form values: a host containing `invalid` / `fail` →
 * ping false (login failure → 422); any other host → ping true (→ ACTIVE).
 * Sync methods return empty results so a stray sync no-ops cleanly.
 */
final class FakeImapClientFactory implements ImapClientFactoryInterface
{
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        $host = strtolower((string) ($connection['host'] ?? ''));
        $pingOk = ! str_contains($host, 'invalid') && ! str_contains($host, 'fail');

        return new FakeImapClient($pingOk);
    }
}

/**
 * @internal companion to {@see FakeImapClientFactory}.
 */
final class FakeImapClient implements ImapClientInterface
{
    /**
     * Deterministic folder set for the offline folder-picker E2E (v8.24). A fixed
     * list so the picker has real-data options without a live server; sync still
     * no-ops because {@see searchUids} returns [].
     *
     * @var list<string>
     */
    public const FAKE_FOLDERS = ['INBOX', '[Gmail]/Sent Mail', 'rotta-logistics-1'];

    public function __construct(private readonly bool $pingOk) {}

    public function ping(): bool
    {
        return $this->pingOk;
    }

    public function close(): void {}

    /** @return list<string> */
    public function listMailboxes(): array
    {
        if (! $this->pingOk) {
            // Unreachable host (host contains 'invalid'/'fail') → mirror the real
            // client's connect failure so the picker's 503 path is reachable too.
            throw new \RuntimeException('FakeImapClient: IMAP connect failed (host marked invalid/fail).');
        }

        return self::FAKE_FOLDERS;
    }

    public function selectMailbox(string $name): MailboxState
    {
        return new MailboxState(uidValidity: 1, lastUid: 0);
    }

    /** @return list<int> */
    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        return [];
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        throw new \LogicException('FakeImapClient: fetchMessage is never reached (searchUids returns []).');
    }
}
