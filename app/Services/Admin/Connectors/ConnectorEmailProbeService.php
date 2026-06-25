<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * "Test fetch" diagnostic — connect to an IMAP account and download the SINGLE
 * most recent message of a folder, returning a sanitized preview WITHOUT running
 * the ingestion pipeline (no Storage write, no IngestDocumentJob). It is the
 * operator's read-only probe to confirm an account's credentials + folder access
 * actually work end-to-end (more than the `health()` ping, which only NOOPs).
 *
 * Why host-side instead of a connector contract: the installed connector packages
 * predate a "fetch sample" capability, so — exactly like the v8.24 folder-picker
 * workaround did — this rebuilds the IMAP client from the connector's own
 * {@see ImapClientFactoryInterface} + the {@see OAuthCredentialVault} secret. It is
 * IMAP-specific by design; other connectors are rejected with a 404.
 *
 * R30 — the lookup is tenant-scoped; a cross-tenant / unknown id 404s.
 * R14 — an unreachable mailbox / rejected credentials raise
 * {@see ConnectorEmailProbeException} (→ 503), never a misleading empty 200. A
 * reachable-but-empty folder is a valid 200 with `message: null`.
 * Privacy — the preview is gated behind `can:manageConnectors` (admin/super-admin,
 * the same trust level that already ingests this mailbox) and the body is reduced
 * to a short truncated snippet; the full message is never returned or persisted.
 */
final class ConnectorEmailProbeService
{
    /** Max characters of body returned in the diagnostic snippet. */
    private const SNIPPET_LIMIT = 280;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ImapClientFactoryInterface $factory,
        private readonly OAuthCredentialVault $vault,
    ) {}

    /**
     * Fetch + sanitize the newest message of an IMAP installation's folder.
     *
     * @return array{
     *     folder: string,
     *     message: array{
     *         uid: int,
     *         subject: string,
     *         from_name: string,
     *         from_email: string,
     *         date: string|null,
     *         to_count: int,
     *         has_attachments: bool,
     *         attachments_count: int,
     *         snippet: string
     *     }|null
     * }
     *
     * @throws NotFoundHttpException        installation absent / cross-tenant / not an IMAP connector
     * @throws ConnectorEmailProbeException  mailbox unreachable / credentials rejected / fetch failed
     */
    public function probe(int $installationId): array
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->first();

        if ($installation === null) {
            throw new NotFoundHttpException("Installation {$installationId} not found.");
        }

        if ($installation->connector_name !== 'imap') {
            // The probe rebuilds an IMAP client; it has no meaning for other
            // connectors (R14 — a clear 404, not a misleading empty result).
            throw new NotFoundHttpException(
                "Test fetch is not supported for connector '{$installation->connector_name}'.",
            );
        }

        $config = (array) ($installation->config_json ?? []);
        $connection = (array) ($config['connection'] ?? []);
        $authMode = (string) ($config['auth_mode'] ?? 'basic');
        $folder = $this->resolveFolder($config);

        $secret = (string) ($this->vault->getAccessToken($installation->id) ?? '');
        if ($secret === '') {
            throw new ConnectorEmailProbeException(
                'No stored credentials for this account — re-add it before testing.',
            );
        }

        $client = $this->factory->make($connection, $secret, $authMode);

        try {
            $state = $client->selectMailbox($folder);
            $newestUid = $state->lastUid;

            if ($newestUid <= 0) {
                // Reachable but empty folder — a valid 200, not a failure.
                return ['folder' => $folder, 'message' => null];
            }

            $message = $client->fetchMessage($folder, $newestUid);

            return ['folder' => $folder, 'message' => $this->preview($message)];
        } catch (Throwable $e) {
            // R14 — surface "couldn't reach / read the mailbox" distinctly; never
            // let it look like an empty-but-successful probe.
            throw new ConnectorEmailProbeException(
                "Impossibile scaricare l'email di prova: {$e->getMessage()}",
                previous: $e,
            );
        } finally {
            $client->close();
        }
    }

    /**
     * The folder to probe: the first whitelisted include path when set, else INBOX.
     *
     * @param  array<string,mixed>  $config
     */
    private function resolveFolder(array $config): string
    {
        $include = (array) (($config['folders'] ?? [])['include'] ?? []);
        foreach ($include as $path) {
            $path = trim((string) $path);
            if ($path !== '') {
                return $path;
            }
        }

        return 'INBOX';
    }

    /**
     * Reduce a full IMAP message to a privacy-conscious diagnostic preview.
     *
     * @return array{uid:int,subject:string,from_name:string,from_email:string,date:string|null,to_count:int,has_attachments:bool,attachments_count:int,snippet:string}
     */
    private function preview(ImapMessage $message): array
    {
        $body = $message->textBody ?? '';
        if ($body === '' && $message->htmlBody !== null) {
            // Strip tags so the snippet is readable text, not raw markup.
            $body = trim(html_entity_decode(strip_tags($message->htmlBody)));
        }

        return [
            'uid' => $message->uid,
            'subject' => $message->subject !== '' ? $message->subject : '(no subject)',
            'from_name' => $message->fromName,
            'from_email' => $message->fromEmail,
            'date' => $message->date?->toIso8601String(),
            'to_count' => count($message->to),
            'has_attachments' => $message->hasAttachments(),
            'attachments_count' => count($message->attachments),
            'snippet' => Str::limit(trim((string) preg_replace('/\s+/', ' ', $body)), self::SNIPPET_LIMIT),
        ];
    }
}
