<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MessageFilter;

/**
 * Process-local coordinator for a single IMAP sync.
 *
 * The upstream connector checkpoints a mailbox only after its whole UID list
 * has completed. When max_messages_per_sync interrupts the inner loop, that
 * assignment is never reached and the next run starts from the old UID. This
 * coordinator records only a contiguous prefix of messages that is known to
 * have completed and persists that prefix independently.
 *
 * A message is confirmed only after:
 *  - the same MessageFilter used by the connector rejects it; or
 *  - the body plus every attachment accepted by AttachmentPolicy has crossed
 *    ConnectorIngestionContract successfully.
 *
 * Consequently, one failed message blocks the watermark before that UID. Later
 * messages may be replayed on the next run, but the failed message is never
 * skipped. The host ingestion path is content-idempotent, so replay is safer
 * than data loss.
 */
final class ImapSyncProgressContext
{
    private ?int $installationId = null;

    private ?string $tenantId = null;

    private ?MessageFilter $filter = null;

    private ?AttachmentPolicy $attachmentPolicy = null;

    /** @var array<string,array<string,mixed>> */
    private array $mailboxesState = [];

    /** @var array<string,true> */
    private array $trackedMailboxes = [];

    /**
     * @var array{
     *     mailbox:string,
     *     uidvalidity:int,
     *     uids:list<int>,
     *     index:int,
     *     blocked:bool,
     *     pending_uid:?int,
     *     pending_dispatches:int
     * }|null
     */
    private ?array $activeMailbox = null;

    private int $confirmedSinceCheckpoint = 0;

    /**
     * Sticky run-level signal. Once a mailbox exposes a gap, later successful
     * UIDs or a move to another mailbox must never make the sync look complete.
     */
    private bool $hasUnconfirmedWork = false;

    public function __construct(
        private readonly OAuthCredentialVault $vault,
        private readonly TenantContext $tenantContext,
        private readonly int $checkpointEvery = 100,
    ) {}

    public function begin(ConnectorInstallation $installation): void
    {
        if ($this->installationId !== null) {
            throw new \LogicException('An IMAP sync progress session is already active.');
        }

        if ($installation->tenant_id !== $this->tenantContext->current()) {
            throw new \LogicException('Cannot start IMAP progress tracking outside the installation tenant.');
        }

        $config = $this->resolvedConfig((array) ($installation->config_json ?? []));
        $extra = $this->vault->getExtra($installation->id);

        $this->installationId = (int) $installation->id;
        $this->tenantId = (string) $installation->tenant_id;
        $this->filter = new MessageFilter($config);
        $this->attachmentPolicy = new AttachmentPolicy((array) ($config['attachments'] ?? []));
        $this->mailboxesState = (array) ($extra['mailboxes_state'] ?? []);
        $this->trackedMailboxes = [];
        $this->activeMailbox = null;
        $this->confirmedSinceCheckpoint = 0;
        $this->hasUnconfirmedWork = false;
    }

    public function isActive(): bool
    {
        return $this->installationId !== null;
    }

    /**
     * Whether the current run ended with at least one UID that was not fully
     * confirmed (body plus every accepted attachment), or with searched UIDs
     * left unprocessed by a pagination cap / early exit.
     *
     * Callers read this at the job boundary before {@see finish()} clears the
     * process-local session.
     */
    public function hasUnconfirmedWork(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->hasUnconfirmedWork || $this->activeMailboxIsIncomplete();
    }

    /**
     * @param  list<int>  $uids
     */
    public function observeSearch(string $mailbox, int $uidValidity, array $uids): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->sealUnconfirmedMessage();
        $this->rememberIncompleteActiveMailbox();

        $prior = (array) ($this->mailboxesState[$mailbox] ?? []);
        $sameValidity = isset($prior['uidvalidity'])
            && (int) $prior['uidvalidity'] === $uidValidity;

        if (! $sameValidity) {
            $prior = [
                'uidvalidity' => $uidValidity,
                'last_uid' => 0,
                'ingested_keys' => [],
            ];
        }

        $this->mailboxesState[$mailbox] = $prior;
        $this->trackedMailboxes[$mailbox] = true;
        $this->activeMailbox = [
            'mailbox' => $mailbox,
            'uidvalidity' => $uidValidity,
            'uids' => array_values(array_map('intval', $uids)),
            'index' => 0,
            'blocked' => false,
            'pending_uid' => null,
            'pending_dispatches' => 0,
        ];
    }

    public function observeFetched(ImapMessage $message): void
    {
        if (! $this->isActive() || $this->activeMailbox === null) {
            return;
        }

        $this->sealUnconfirmedMessage();

        if ($this->activeMailbox['blocked']) {
            return;
        }

        if (
            $message->mailbox !== $this->activeMailbox['mailbox']
            || $message->uidValidity !== $this->activeMailbox['uidvalidity']
            || $this->expectedUid() !== $message->uid
        ) {
            $this->activeMailbox['blocked'] = true;
            $this->hasUnconfirmedWork = true;

            return;
        }

        if (! $this->filter?->passes($message)) {
            $this->confirm($message->uid);

            return;
        }

        $this->activeMailbox['pending_uid'] = $message->uid;
        $this->activeMailbox['pending_dispatches'] = $this->expectedDispatchCount($message);
    }

    /**
     * Called only after the host ingestion bridge returned successfully.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function recordSuccessfulDispatch(array $metadata, string $tenantId): void
    {
        if (! $this->isActive() || $this->activeMailbox === null) {
            return;
        }

        if ($tenantId !== $this->tenantId) {
            return;
        }

        if (($metadata['connector'] ?? null) !== 'imap') {
            return;
        }

        if ((int) ($metadata['installation_id'] ?? 0) !== $this->installationId) {
            return;
        }

        $mailbox = (string) ($metadata['imap_mailbox'] ?? '');
        $uid = filter_var($metadata['imap_uid'] ?? null, FILTER_VALIDATE_INT);
        $docKey = (string) ($metadata['imap_doc_key'] ?? '');

        if (
            $uid === false
            || $mailbox !== $this->activeMailbox['mailbox']
            || $uid !== $this->activeMailbox['pending_uid']
            || $docKey !== $mailbox.':'.$this->activeMailbox['uidvalidity'].':'.$uid
            || $this->activeMailbox['pending_dispatches'] < 1
        ) {
            return;
        }

        $this->activeMailbox['pending_dispatches']--;

        if ($this->activeMailbox['pending_dispatches'] === 0) {
            $this->confirm($uid);
        }
    }

    /**
     * Persist the safe prefix after the connector's own finally block, so its
     * stale cap-path state cannot overwrite this checkpoint.
     */
    public function finish(): void
    {
        if (! $this->isActive()) {
            return;
        }

        $this->sealUnconfirmedMessage();
        $this->rememberIncompleteActiveMailbox();

        try {
            // Persist even when the last periodic checkpoint landed exactly on
            // the boundary: the package writes its stale state in its own
            // finally block after that checkpoint, so this post-run write must
            // always win for every mailbox observed by the decorator.
            if ($this->trackedMailboxes !== []) {
                $this->persist();
            }
        } finally {
            $this->installationId = null;
            $this->tenantId = null;
            $this->filter = null;
            $this->attachmentPolicy = null;
            $this->mailboxesState = [];
            $this->trackedMailboxes = [];
            $this->activeMailbox = null;
            $this->confirmedSinceCheckpoint = 0;
            $this->hasUnconfirmedWork = false;
        }
    }

    private function sealUnconfirmedMessage(): void
    {
        if (
            $this->activeMailbox !== null
            && $this->activeMailbox['pending_uid'] !== null
            && $this->activeMailbox['pending_dispatches'] > 0
        ) {
            $this->activeMailbox['blocked'] = true;
            $this->hasUnconfirmedWork = true;
            $this->activeMailbox['pending_uid'] = null;
            $this->activeMailbox['pending_dispatches'] = 0;
        }
    }

    private function rememberIncompleteActiveMailbox(): void
    {
        if ($this->activeMailboxIsIncomplete()) {
            $this->hasUnconfirmedWork = true;
        }
    }

    private function activeMailboxIsIncomplete(): bool
    {
        if ($this->activeMailbox === null) {
            return false;
        }

        return $this->activeMailbox['blocked']
            || $this->activeMailbox['pending_uid'] !== null
            || $this->activeMailbox['pending_dispatches'] > 0
            || $this->activeMailbox['index'] < count($this->activeMailbox['uids']);
    }

    private function expectedUid(): ?int
    {
        if ($this->activeMailbox === null) {
            return null;
        }

        return $this->activeMailbox['uids'][$this->activeMailbox['index']] ?? null;
    }

    private function confirm(int $uid): void
    {
        if (
            $this->activeMailbox === null
            || $this->activeMailbox['blocked']
            || $this->expectedUid() !== $uid
        ) {
            if ($this->activeMailbox !== null) {
                $this->activeMailbox['blocked'] = true;
                $this->hasUnconfirmedWork = true;
            }

            return;
        }

        $mailbox = $this->activeMailbox['mailbox'];
        $uidValidity = $this->activeMailbox['uidvalidity'];
        $state = (array) ($this->mailboxesState[$mailbox] ?? []);
        $keys = array_map('strval', (array) ($state['ingested_keys'] ?? []));
        $keys[] = $mailbox.':'.$uidValidity.':'.$uid;

        $state['uidvalidity'] = $uidValidity;
        $state['last_uid'] = $uid;
        $state['ingested_keys'] = array_slice(array_values(array_unique($keys)), -1000);
        $this->mailboxesState[$mailbox] = $state;

        $this->activeMailbox['index']++;
        $this->activeMailbox['pending_uid'] = null;
        $this->activeMailbox['pending_dispatches'] = 0;
        $this->confirmedSinceCheckpoint++;

        if ($this->confirmedSinceCheckpoint >= max(1, $this->checkpointEvery)) {
            $this->persist();
        }
    }

    private function persist(): void
    {
        if ($this->installationId === null) {
            return;
        }

        $latestExtra = $this->vault->getExtra($this->installationId);
        $latestState = (array) ($latestExtra['mailboxes_state'] ?? []);

        foreach (array_keys($this->trackedMailboxes) as $mailbox) {
            $tracked = (array) ($this->mailboxesState[$mailbox] ?? []);
            $latest = (array) ($latestState[$mailbox] ?? []);
            $sameValidity = isset($latest['uidvalidity'], $tracked['uidvalidity'])
                && (int) $latest['uidvalidity'] === (int) $tracked['uidvalidity'];
            $latestKeys = $sameValidity
                ? array_map('strval', (array) ($latest['ingested_keys'] ?? []))
                : [];
            $keys = array_values(array_unique(array_merge(
                $latestKeys,
                array_map('strval', (array) ($tracked['ingested_keys'] ?? [])),
            )));

            $latestState[$mailbox] = array_merge($latest, $tracked, [
                'ingested_keys' => array_slice($keys, -1000),
            ]);
        }

        $this->vault->setExtraKey(
            $this->installationId,
            'mailboxes_state',
            $latestState,
        );

        $this->confirmedSinceCheckpoint = 0;
    }

    private function expectedDispatchCount(ImapMessage $message): int
    {
        $policy = $this->attachmentPolicy;
        if ($policy === null) {
            return 1;
        }

        $accepted = 0;
        $limit = max(0, $policy->limit());

        foreach ($message->attachments as $attachment) {
            if ($accepted >= $limit) {
                break;
            }

            if ($policy->accepts($attachment)) {
                $accepted++;
            }
        }

        return 1 + $accepted;
    }

    /**
     * Match ImapConnector::resolveConfig() for the fields used by
     * MessageFilter and AttachmentPolicy.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function resolvedConfig(array $config): array
    {
        $defaults = (array) config('connectors.providers.imap.defaults', []);
        $config['attachments'] = array_merge(
            (array) ($defaults['attachments'] ?? []),
            (array) ($config['attachments'] ?? []),
        );
        $config['date_window_days'] ??= $defaults['date_window_days'] ?? 365;
        $config['skip_auto_generated'] ??= $defaults['skip_auto_generated'] ?? true;
        $config['body_format'] ??= $defaults['body_format'] ?? 'prefer_text';

        return $config;
    }
}
