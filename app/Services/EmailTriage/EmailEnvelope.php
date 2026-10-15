<?php

declare(strict_types=1);

namespace App\Services\EmailTriage;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use RuntimeException;

/** The frozen v1 contract is validated before host persistence or dispatch. */
final class EmailEnvelope
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function validate(array $envelope): ConnectorInstallation
    {
        if (($envelope['schema_version'] ?? null) !== 1
            || ! is_string($envelope['tenant_id'] ?? null) || $envelope['tenant_id'] === ''
            || ! is_int($envelope['installation_id'] ?? null) || $envelope['installation_id'] < 1
            || ! is_string($envelope['project_key'] ?? null) || $envelope['project_key'] === ''
            || ! is_array($envelope['metadata'] ?? null)
            || ! is_array($envelope['manifest'] ?? null) || ! array_is_list($envelope['manifest'])
            || ! array_key_exists('email_date', $envelope)) {
            throw new RuntimeException('Invalid prepared email envelope.');
        }
        $installation = ConnectorInstallation::query()->where('tenant_id', $envelope['tenant_id'])
            ->findOrFail($envelope['installation_id']);
        $this->validateMetadata($envelope['metadata'], $envelope['tenant_id'], $installation);
        if ($envelope['project_key'] !== EmailConnectorRegistry::resolveProject($installation)
            || $envelope['email_date'] !== $envelope['metadata']['email_date']) {
            throw new RuntimeException('Prepared email scope does not match its installation.');
        }
        $manifest = $envelope['manifest'];
        // Keep hostile manifests bounded independently of the upstream attachment policy.
        if (count($manifest) > 1000 || strlen(json_encode($manifest, self::JSON_FLAGS)) > 1048576
            || count($manifest) !== $envelope['metadata']['email_attachment_count']) {
            throw new RuntimeException('Invalid prepared email attachment count or budget.');
        }
        $lastId = null;
        $slots = [];
        foreach ($manifest as $file) {
            if (! is_array($file) || ! is_int($file['slot'] ?? null) || $file['slot'] < 0
                || isset($slots[$file['slot']]) || ! $this->nonemptyString($file['id'] ?? null, 2048)
                || ! $this->nonemptyString($file['name'] ?? null, 1024)
                || ! $this->nonemptyString($file['mime'] ?? null, 255)
                || ! in_array($file['status'] ?? null, ['pending', 'excluded'], true)
                || ! array_key_exists('hash', $file) || ! array_key_exists('reason', $file)
                || ($lastId !== null && strcmp($lastId, $file['id']) >= 0)) {
                throw new RuntimeException('Invalid prepared email attachment manifest.');
            }
            if (($file['status'] === 'pending' && (! $this->isHash($file['hash']) || $file['reason'] !== null))
                || ($file['status'] === 'excluded' && ($file['hash'] !== null || ! $this->nonemptyString($file['reason'], 255)))) {
                throw new RuntimeException('Invalid prepared email attachment commitment.');
            }
            $slots[$file['slot']] = true;
            $lastId = $file['id'];
        }
        if (! hash_equals($this->revisionHash($envelope['metadata']['email_body_hash'], $manifest), $envelope['metadata']['email_revision_hash'])) {
            throw new RuntimeException('Prepared email revision commitment does not match its manifest.');
        }

        return $installation;
    }

    public function validateMetadata(array $metadata, string $tenant, ConnectorInstallation $installation): void
    {
        $key = $metadata['connector'] ?? null;
        if (! is_string($key) || ! EmailConnectorRegistry::isApi($key)
            || $metadata['connector'] !== $installation->connector_name
            || ($metadata['email_provider'] ?? null) !== $key
            || ($metadata['email_schema_version'] ?? null) !== 1
            || ($metadata['installation_id'] ?? null) !== (int) $installation->id
            || $tenant !== (string) $installation->tenant_id
            || (isset($metadata['tenant_id']) && $metadata['tenant_id'] !== $tenant)
            || (isset($metadata['project_key']) && $metadata['project_key'] !== EmailConnectorRegistry::resolveProject($installation))
            || ! $this->nonemptyString($metadata['email_mailbox_id'] ?? null, 2048)
            || ! $this->nonemptyString($metadata['email_message_id'] ?? null, 2048)
            || ! $this->isHash($metadata['email_body_hash'] ?? null)
            || ! $this->isHash($metadata['email_revision_hash'] ?? null)
            || ! is_int($metadata['email_attachment_count'] ?? null) || $metadata['email_attachment_count'] < 0
            || ! array_key_exists('email_date', $metadata)
            || ! $this->date($metadata['email_date'])) {
            throw new RuntimeException('Invalid email v1 metadata or installation scope.');
        }
        foreach (['email_folder_ids', 'email_folder_names'] as $field) {
            if (! is_array($metadata[$field] ?? null) || ! array_is_list($metadata[$field])
                || count($metadata[$field]) > 1000) {
                throw new RuntimeException('Invalid email v1 folders.');
            }
            foreach ($metadata[$field] as $value) {
                if (! $this->nonemptyString($value, 2048)) {
                    throw new RuntimeException('Invalid email v1 folder identifier.');
                }
            }
        }
        $identity = [$tenant, (int) $installation->id, $key, $metadata['email_mailbox_id'], $metadata['email_message_id']];
        $expected = $key.':'.hash('sha256', json_encode($identity, self::JSON_FLAGS));
        if (($metadata['email_message_key'] ?? null) !== $expected) {
            throw new RuntimeException('Email v1 message identity does not match its scope.');
        }
    }

    public function revisionHash(string $bodyHash, array $manifest): string
    {
        $semantic = array_map(static fn (array $file): array => [
            $file['slot'], $file['id'], $file['name'], $file['mime'], $file['hash'],
            $file['status'] === 'excluded' ? $file['reason'] : null,
        ], $manifest);

        return hash('sha256', json_encode([$bodyHash, $semantic], self::JSON_FLAGS));
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function nonemptyString(mixed $value, int $limit): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit && mb_check_encoding($value, 'UTF-8');
    }

    private function date(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value) || strlen($value) > 64 || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (\Exception) {
            return false;
        }
    }
}
