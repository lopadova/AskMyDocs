<?php

declare(strict_types=1);

namespace App\Services\Demo;

use FilesystemIterator;
use JsonException;
use RuntimeException;

/**
 * Atomic local checkpoint storage for resumable IMAP fixture delivery.
 *
 * The filename identifies the physical mailbox + dataset version, while the
 * manifest checksum is verified inside the payload. Reusing a version with
 * different bytes therefore fails loudly instead of silently resuming against
 * a different corpus.
 */
final readonly class EmailSeedCheckpointStore
{
    private const MAX_CHECKPOINT_BYTES = 65_536;

    private const PURGE_INTENT_SUFFIX = '.purge-intent.json';

    public function __construct(private ?string $directory = null) {}

    public function exists(
        MailboxTarget $target,
        string $datasetVersion,
    ): bool {
        return is_file($this->path($target, $datasetVersion));
    }

    public function load(
        MailboxTarget $target,
        string $datasetVersion,
        string $manifestChecksum,
    ): EmailSeedCheckpoint {
        $path = $this->path($target, $datasetVersion);
        if (! is_file($path)) {
            return new EmailSeedCheckpoint($target->mailboxKey, $datasetVersion, $manifestChecksum);
        }

        $payload = $this->readPayload($path);
        if (
            ($payload['mailbox_key'] ?? null) !== $target->mailboxKey
            || ($payload['dataset_version'] ?? null) !== $datasetVersion
        ) {
            throw new RuntimeException("Identità checkpoint e-mail incoerente: {$path}");
        }
        if (($payload['manifest_checksum'] ?? null) !== $manifestChecksum) {
            throw new RuntimeException(
                "Il manifest della dataset {$datasetVersion} è cambiato: "
                .'usa una nuova dataset version oppure elimina esplicitamente il checkpoint.',
            );
        }

        $lastSequence = $this->nonNegativeInteger($payload, 'last_sequence', $path);
        $appended = $this->nonNegativeInteger($payload, 'appended', $path);
        $alreadyPresent = $this->nonNegativeInteger($payload, 'already_present', $path);

        if ($appended + $alreadyPresent !== $lastSequence) {
            throw new RuntimeException("Conteggi checkpoint e-mail incoerenti: {$path}");
        }

        return new EmailSeedCheckpoint(
            mailboxKey: $target->mailboxKey,
            datasetVersion: $datasetVersion,
            manifestChecksum: $manifestChecksum,
            lastSequence: $lastSequence,
            appended: $appended,
            alreadyPresent: $alreadyPresent,
        );
    }

    public function save(MailboxTarget $target, EmailSeedCheckpoint $checkpoint): void
    {
        $this->ensureRoot();

        $path = $this->path($target, $checkpoint->datasetVersion);
        try {
            $json = json_encode([
                'schema_version' => 1,
                'mailbox_key' => $checkpoint->mailboxKey,
                'dataset_version' => $checkpoint->datasetVersion,
                'manifest_checksum' => $checkpoint->manifestChecksum,
                'last_sequence' => $checkpoint->lastSequence,
                'appended' => $checkpoint->appended,
                'already_present' => $checkpoint->alreadyPresent,
                'updated_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        } catch (JsonException $e) {
            throw new RuntimeException('Impossibile serializzare il checkpoint e-mail.', previous: $e);
        }

        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $written = file_put_contents($temporary, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            if (is_file($temporary) && ! unlink($temporary)) {
                throw new RuntimeException(
                    "Scrittura checkpoint parziale e cleanup fallito: {$temporary}",
                );
            }

            throw new RuntimeException("Scrittura checkpoint e-mail fallita: {$temporary}");
        }

        if (! rename($temporary, $path)) {
            if (is_file($temporary) && ! unlink($temporary)) {
                throw new RuntimeException(
                    "Commit checkpoint fallito e cleanup fallito: {$temporary}",
                );
            }

            throw new RuntimeException("Commit atomico checkpoint e-mail fallito: {$path}");
        }
    }

    /**
     * Persist the remote mutation intent before the first destructive IMAP
     * operation. There can be at most one pending purge per physical mailbox.
     */
    public function beginPurge(
        MailboxTarget $target,
        EmailSeedPurgeIntent $intent,
    ): void {
        if ($intent->mailboxKey !== $target->mailboxKey) {
            throw new RuntimeException(
                'Il purge intent non appartiene alla mailbox logica selezionata.',
            );
        }

        $this->ensureRoot();
        $path = $this->purgeIntentPath($target);
        if (file_exists($path)) {
            throw new RuntimeException(
                "Esiste già un purge intent incompleto per la mailbox fisica: {$path}",
            );
        }

        try {
            $json = json_encode([
                'schema_version' => 1,
                'kind' => 'email_seed_purge_intent',
                'physical_mailbox_hash' => $this->physicalMailboxHash($target),
                'mailbox_key' => $intent->mailboxKey,
                'operation' => $intent->operation,
                'header_name' => $intent->headerName,
                'header_value' => $intent->headerValue,
                'dataset_version' => $intent->datasetVersion,
                'manifest_checksum' => $intent->manifestChecksum,
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        } catch (JsonException $e) {
            throw new RuntimeException('Impossibile serializzare il purge intent e-mail.', previous: $e);
        }

        $this->writeAtomically(
            path: $path,
            contents: $json,
            description: 'purge intent e-mail',
        );
    }

    public function pendingPurge(MailboxTarget $target): ?EmailSeedPurgeIntent
    {
        $path = $this->purgeIntentPath($target);
        if (! file_exists($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("Purge intent e-mail non regolare: {$path}");
        }

        $payload = $this->readJsonPayload($path, 'purge intent e-mail');
        if (
            ($payload['schema_version'] ?? null) !== 1
            || ($payload['kind'] ?? null) !== 'email_seed_purge_intent'
        ) {
            throw new RuntimeException("Purge intent e-mail non supportato: {$path}");
        }
        if (($payload['physical_mailbox_hash'] ?? null) !== $this->physicalMailboxHash($target)) {
            throw new RuntimeException("Identità mailbox incoerente nel purge intent: {$path}");
        }

        foreach ([
            'mailbox_key',
            'operation',
            'header_name',
            'header_value',
        ] as $field) {
            if (! is_string($payload[$field] ?? null)) {
                throw new RuntimeException("Campo {$field} non valido nel purge intent: {$path}");
            }
        }
        foreach (['dataset_version', 'manifest_checksum'] as $field) {
            if (($payload[$field] ?? null) !== null && ! is_string($payload[$field])) {
                throw new RuntimeException("Campo {$field} non valido nel purge intent: {$path}");
            }
        }

        try {
            return new EmailSeedPurgeIntent(
                mailboxKey: $payload['mailbox_key'],
                operation: $payload['operation'],
                headerName: $payload['header_name'],
                headerValue: $payload['header_value'],
                datasetVersion: $payload['dataset_version'] ?? null,
                manifestChecksum: $payload['manifest_checksum'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException("Purge intent e-mail corrotto: {$path}", previous: $e);
        }
    }

    public function completePurge(MailboxTarget $target): void
    {
        $path = $this->purgeIntentPath($target);
        if (! is_file($path)) {
            throw new RuntimeException("Purge intent e-mail assente durante il completamento: {$path}");
        }
        if (! unlink($path)) {
            throw new RuntimeException("Impossibile eliminare il purge intent e-mail: {$path}");
        }
    }

    public function clear(MailboxTarget $target, string $datasetVersion): void
    {
        $path = $this->path($target, $datasetVersion);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException("Impossibile eliminare il checkpoint e-mail: {$path}");
        }
    }

    /**
     * Clears every dataset version belonging to one physical mailbox.
     *
     * Checkpoint filenames are hashes of host, port, account, folder and
     * dataset version. The directory is therefore streamed one entry at a
     * time; each payload yields its version and the expected filename is
     * recomputed for the target. This keeps memory bounded and prevents a
     * broad purge from deleting checkpoints for another account or folder.
     */
    public function clearAll(MailboxTarget $target): int
    {
        $directory = $this->root();
        if (! file_exists($directory)) {
            return 0;
        }
        if (! is_dir($directory)) {
            throw new RuntimeException("Il percorso checkpoint e-mail non è una directory: {$directory}");
        }

        $cleared = 0;
        $entries = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        foreach ($entries as $entry) {
            if (
                $entry->isLink()
                || ! $entry->isFile()
                || preg_match('/^[a-f0-9]{64}\.json$/D', $entry->getFilename()) !== 1
            ) {
                continue;
            }

            $path = $entry->getPathname();
            $payload = $this->readPayload($path);
            $datasetVersion = $payload['dataset_version'] ?? null;
            if (
                ! is_string($datasetVersion)
                || preg_match('/^[a-z0-9-]+$/D', $datasetVersion) !== 1
            ) {
                throw new RuntimeException("Dataset version non valida nel checkpoint: {$path}");
            }

            $expectedFilename = basename($this->path($target, $datasetVersion));
            if ($expectedFilename !== $entry->getFilename()) {
                continue;
            }

            if (! unlink($path)) {
                throw new RuntimeException("Impossibile eliminare il checkpoint e-mail: {$path}");
            }
            $cleared++;
        }

        return $cleared;
    }

    private function path(MailboxTarget $target, string $datasetVersion): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $datasetVersion) !== 1) {
            throw new RuntimeException("Dataset version non valida: {$datasetVersion}");
        }

        try {
            $identity = json_encode([
                'host' => strtolower($target->host),
                'port' => $target->port,
                'account' => strtolower($target->email),
                'folder' => $target->folder,
                'dataset_version' => $datasetVersion,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Impossibile calcolare l’identità del checkpoint.', previous: $e);
        }

        return $this->root().DIRECTORY_SEPARATOR.hash('sha256', $identity).'.json';
    }

    private function purgeIntentPath(MailboxTarget $target): string
    {
        return $this->root()
            .DIRECTORY_SEPARATOR
            .$this->physicalMailboxHash($target)
            .self::PURGE_INTENT_SUFFIX;
    }

    private function physicalMailboxHash(MailboxTarget $target): string
    {
        try {
            $identity = json_encode([
                'host' => strtolower($target->host),
                'port' => $target->port,
                'account' => strtolower($target->email),
                'folder' => $target->folder,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Impossibile calcolare l’identità della mailbox fisica.', previous: $e);
        }

        return hash('sha256', $identity);
    }

    private function ensureRoot(): void
    {
        $directory = $this->root();
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossibile creare la directory checkpoint e-mail: {$directory}");
        }
    }

    private function writeAtomically(
        string $path,
        string $contents,
        string $description,
    ): void {
        // The deterministic temporary path prevents unbounded orphan growth
        // across repeated hard process deaths. Mailbox serialization ensures
        // only one writer can own this path.
        $temporary = $path.'.tmp';
        $written = file_put_contents($temporary, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            if (is_file($temporary) && ! unlink($temporary)) {
                throw new RuntimeException(
                    "Scrittura {$description} parziale e cleanup fallito: {$temporary}",
                );
            }

            throw new RuntimeException("Scrittura {$description} fallita: {$temporary}");
        }

        if (! rename($temporary, $path)) {
            if (is_file($temporary) && ! unlink($temporary)) {
                throw new RuntimeException(
                    "Commit {$description} fallito e cleanup fallito: {$temporary}",
                );
            }

            throw new RuntimeException("Commit atomico {$description} fallito: {$path}");
        }
    }

    private function root(): string
    {
        return rtrim(
            $this->directory ?? storage_path('app/email-seed-checkpoints'),
            DIRECTORY_SEPARATOR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(string $path): array
    {
        $payload = $this->readJsonPayload($path, 'checkpoint e-mail');

        if (($payload['schema_version'] ?? null) !== 1) {
            throw new RuntimeException("Checkpoint e-mail non supportato: {$path}");
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPayload(string $path, string $description): array
    {
        $raw = file_get_contents(
            $path,
            false,
            null,
            0,
            self::MAX_CHECKPOINT_BYTES + 1,
        );
        if ($raw === false) {
            throw new RuntimeException("Impossibile leggere {$description}: {$path}");
        }
        if (strlen($raw) > self::MAX_CHECKPOINT_BYTES) {
            throw new RuntimeException(
                ucfirst($description).' troppo grande (massimo '
                .self::MAX_CHECKPOINT_BYTES." byte): {$path}",
            );
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(ucfirst($description)." corrotto: {$path}", previous: $e);
        }

        if (! is_array($payload)) {
            throw new RuntimeException(ucfirst($description)." non valido: {$path}");
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nonNegativeInteger(array $payload, string $field, string $path): int
    {
        $value = $payload[$field] ?? null;
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException("Campo {$field} non valido nel checkpoint: {$path}");
        }

        return $value;
    }
}
