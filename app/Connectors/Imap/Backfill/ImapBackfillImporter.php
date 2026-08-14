<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\EmailToMarkdown;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MessageFilter;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;
use RuntimeException;

final class ImapBackfillImporter
{
    public function __construct(
        private readonly ImapBackfillClientProviderContract $clients,
        private readonly ConnectorIngestionContract $ingestion,
    ) {}

    public function importBatch(
        ConnectorInstallation $installation,
        ImapBackfill $backfill,
        ImapBackfillWindow $window,
    ): ImapBackfillBatchResult {
        $client = $this->clients->forInstallation($installation);
        $config = $this->resolvedConfig((array) ($backfill->settings_json ?? []));
        $limit = max(1, (int) $backfill->batch_size);

        try {
            $state = $client->selectMailbox($window->mailbox);
            if ($window->snapshot_uid_validity > 0 && $state->uidValidity !== $window->snapshot_uid_validity) {
                throw new RuntimeException(
                    "UIDVALIDITY changed for {$window->mailbox}; start a new backfill snapshot."
                );
            }
            // Ask the IMAP server for one item beyond the batch so hasMore is
            // known without transferring every remaining UID in the window.
            $uids = $client->uidsBetween(
                $window->mailbox,
                $window->window_start->copy()->startOfDay(),
                $window->window_end->copy()->startOfDay(),
                (int) $window->last_uid,
                (int) $window->snapshot_max_uid,
                $limit + 1,
            );

            $expected = (int) $window->processed_messages + count($uids);
            $batch = array_slice($uids, 0, $limit);
            $filter = new MessageFilter($config);
            $policy = new AttachmentPolicy((array) ($config['attachments'] ?? []));
            $processed = 0;
            $dispatched = 0;
            $lastUid = (int) $window->last_uid;

            $fetchSize = max(1, (int) config('connectors.imap.backfill.fetch_size', 20));
            foreach (array_chunk($batch, $fetchSize) as $uidChunk) {
                $messages = $client->fetchMessages($window->mailbox, $uidChunk);
                $byUid = [];
                foreach ($messages as $message) {
                    $byUid[$message->uid] = $message;
                }

                // The checkpoint advances only across a contiguous, successfully
                // persisted UID prefix. A missing UID therefore retries safely.
                foreach ($uidChunk as $uid) {
                    $message = $byUid[$uid] ?? null;
                    if ($message === null) {
                        throw new RuntimeException("IMAP bulk fetch did not return UID {$uid}");
                    }
                    if ($filter->passes($message)) {
                        $dispatched += $this->persistMessage($installation, $config, $message, $policy);
                    }
                    $processed++;
                    $lastUid = $uid;
                }
            }

            return new ImapBackfillBatchResult(
                expectedMessages: $expected,
                processedMessages: $processed,
                dispatchedDocuments: $dispatched,
                lastUid: $lastUid,
                hasMore: count($uids) > count($batch),
            );
        } finally {
            $client->close();
        }
    }

    /** @param array<string,mixed> $config */
    private function persistMessage(
        ConnectorInstallation $installation,
        array $config,
        ImapMessage $message,
        AttachmentPolicy $policy,
    ): int {
        $projectKey = $this->projectKey($installation);
        $mailboxSlug = sprintf(
            '%s-%s',
            Str::slug($message->mailbox) ?: 'folder',
            substr(hash('sha256', $message->mailbox), 0, 12),
        );
        $preferText = (string) ($config['body_format'] ?? 'prefer_text') === 'prefer_text';
        $markdown = (new EmailToMarkdown)->render(
            $message,
            $preferText,
            (bool) ($config['strip_quoted_history'] ?? false),
        );
        if (($config['redact_pii'] ?? false) === true) {
            $markdown = $this->ingestion->redactContent($markdown);
        }

        $relative = sprintf(
            '%s/connectors/imap/installation-%d/%s/%d.md',
            $projectKey,
            $installation->id,
            $mailboxSlug,
            $message->uid,
        );
        $paths = $this->ingestion->resolveKbSourcePath($relative);
        if (! Storage::disk($paths['disk'])->put($paths['absolute'], $markdown)) {
            throw new RuntimeException("Cannot write IMAP document {$relative}");
        }

        $this->ingestion->dispatchIngestion(
            $projectKey,
            $paths['relative'],
            $paths['disk'],
            $message->subject !== '' ? $message->subject : '(no subject)',
            (new MailMetadata)->build($installation->id, $message),
            'text/markdown',
            (string) $installation->tenant_id,
        );
        $count = 1;

        $emitted = 0;
        foreach ($message->attachments as $attachment) {
            if ($emitted >= $policy->limit()) {
                break;
            }
            if (! $policy->accepts($attachment)) {
                continue;
            }
            $emitted++;
            $safe = Str::slug(pathinfo($attachment->filename, PATHINFO_FILENAME)) ?: 'file';
            $extension = pathinfo($attachment->filename, PATHINFO_EXTENSION);
            $attachmentRelative = sprintf(
                '%s/connectors/imap/installation-%d/%s/%d/%s%s',
                $projectKey,
                $installation->id,
                $mailboxSlug,
                $message->uid,
                $safe,
                $extension !== '' ? '.'.$extension : '',
            );
            $attachmentPaths = $this->ingestion->resolveKbSourcePath($attachmentRelative);
            if (! Storage::disk($attachmentPaths['disk'])->put($attachmentPaths['absolute'], $attachment->contents)) {
                throw new RuntimeException("Cannot write IMAP attachment {$attachmentRelative}");
            }

            $metadata = (new MailMetadata)->build($installation->id, $message);
            $metadata['attachment_of_message_id'] = $message->messageId;
            $metadata['attachment_filename'] = $attachment->filename;
            $this->ingestion->dispatchIngestion(
                $projectKey,
                $attachmentPaths['relative'],
                $attachmentPaths['disk'],
                $attachment->filename,
                $metadata,
                $attachment->mimeType !== '' ? $attachment->mimeType : 'application/octet-stream',
                (string) $installation->tenant_id,
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function resolvedConfig(array $config): array
    {
        $defaults = (array) config('connectors.providers.imap.defaults', []);
        $config['attachments'] = array_merge(
            (array) ($defaults['attachments'] ?? []),
            (array) ($config['attachments'] ?? []),
        );
        $config['skip_auto_generated'] ??= $defaults['skip_auto_generated'] ?? true;
        $config['body_format'] ??= $defaults['body_format'] ?? 'prefer_text';

        return $config;
    }

    private function projectKey(ConnectorInstallation $installation): string
    {
        if (is_string($installation->project_key) && $installation->project_key !== '') {
            return $installation->project_key;
        }
        $config = (array) ($installation->config_json ?? []);
        $legacy = $config['project_key'] ?? null;
        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }
        $fallback = config('kb.ingest.default_project');

        return is_string($fallback) && $fallback !== '' ? $fallback : 'default';
    }
}
