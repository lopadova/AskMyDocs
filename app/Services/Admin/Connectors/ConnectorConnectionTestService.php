<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * PRE-SAVE connection test for a credential connector (IMAP today). Given the
 * SUBMITTED credential-form values it pings the server and reports whether the
 * login works — WITHOUT persisting anything: no `connector_installations` row,
 * no vault write, no audit. It is the "Test connection" button behind the
 * modal, letting the operator confirm the credentials before clicking Connect
 * (and letting the FE gate Connect on a passing test).
 *
 * It is the read-only sibling of {@see ConfigureConnectorService::configure()}:
 * both derive the SAME connection params from the schema (a field's `target` —
 * `connection` values build the client, the single `secret` is the login
 * credential), but this one never writes and never keeps a client open.
 *
 * IMAP-focused by design, mirroring {@see ConnectorEmailProbeService}: it
 * rebuilds the client from the connector's own {@see ImapClientFactoryInterface}
 * (already the per-mailbox serializing decorator, so it honours the one-live-
 * connection-per-mailbox guarantee and releases the lock via close()). Only
 * basic (password) auth has a synchronous pre-save ping; xoauth2 is verified by
 * the provider sign-in round-trip and is rejected here with a clear message.
 *
 * A failure raises {@see ConnectorConnectionTestException} (→ the controller's
 * 200 `{ ok:false, error }`), NEVER a persisted side effect. The secret is only
 * held in memory for the ping and never logged.
 */
final class ConnectorConnectionTestService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ImapClientFactoryInterface $factory,
    ) {}

    /**
     * Ping the server with the submitted credentials; return on success, throw
     * {@see ConnectorConnectionTestException} on any reason the test can't pass.
     *
     * @param  array<string,mixed>  $payload  The submitted credential-form values (schema-keyed).
     *
     * @throws NotFoundHttpException             unknown / non-credential connector
     * @throws ConnectorConnectionTestException  unreachable / rejected / missing fields / unsupported auth mode
     */
    public function test(string $name, array $payload): void
    {
        $connector = $this->registry->get($name);

        if (! $connector instanceof SupportsCredentialForm) {
            throw new NotFoundHttpException("Connector '{$name}' does not support credential configuration.");
        }

        $authMode = (string) ($payload['auth_mode'] ?? 'basic');

        if ($authMode !== 'basic') {
            // xoauth2 has no synchronous pre-save ping — the provider sign-in
            // round-trip IS its test. Say so plainly instead of a misleading fail.
            throw new ConnectorConnectionTestException(
                'Connection testing is available only for password authentication; for OAuth, use the provider sign-in.',
            );
        }

        [$connection, $secret] = $this->extractConnectionAndSecret(
            $connector->credentialFormSchema(),
            $payload,
            $authMode,
        );

        if ((string) ($connection['host'] ?? '') === '' || (string) ($connection['username'] ?? '') === '' || $secret === null || $secret === '') {
            throw new ConnectorConnectionTestException(
                'Enter the host, username and password before testing the connection.',
            );
        }

        $client = null;

        try {
            $client = $this->factory->make($connection, $secret, $authMode);

            if (! $client->ping()) {
                throw new ConnectorConnectionTestException(
                    'Connection refused — check the credentials and the server settings.',
                );
            }
        } catch (ConnectorConnectionTestException $e) {
            throw $e;
        } catch (Throwable $e) {
            // A factory-level failure (bad host, handshake rejected in make()) or a
            // ping that throws is the same "couldn't reach the mailbox" answer — a
            // negative test result carrying the reason, never a 500.
            throw new ConnectorConnectionTestException("Could not connect: {$e->getMessage()}", previous: $e);
        } finally {
            // Release the per-mailbox connection lock even on failure.
            $client?->close();
        }
    }

    /**
     * Derive the connection params + the single secret from the schema, exactly
     * as {@see ConfigureConnectorService::configure()} would persist them: a
     * field's `target='connection'` value feeds the client, the `target='secret'`
     * field is the login credential. Fields hidden by an unmet `showIf` for the
     * submitted auth mode are skipped, and an omitted field falls back to its
     * schema default (so port 993 etc. apply just like on save).
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $payload
     * @return array{0: array<string,mixed>, 1: ?string}  [connection, secret]
     */
    private function extractConnectionAndSecret(array $schema, array $payload, string $authMode): array
    {
        $connection = [];
        $secret = null;
        $seenSecretField = false;

        foreach ($schema as $field) {
            $fieldName = (string) ($field['name'] ?? '');
            if ($fieldName === '') {
                continue;
            }

            $showIf = $field['showIf'] ?? null;
            if (is_array($showIf) && isset($showIf['field'], $showIf['equals'])) {
                $controlling = $showIf['field'] === 'auth_mode'
                    ? $authMode
                    : ($payload[$showIf['field']] ?? null);
                if ($controlling !== $showIf['equals']) {
                    continue;
                }
            }

            $value = array_key_exists($fieldName, $payload)
                ? $payload[$fieldName]
                : ($field['default'] ?? null);

            $target = (string) ($field['target'] ?? '');

                if ($seenSecretField) {
                    throw new ConnectorConnectionTestException(
                        'Credential connector schema declares more than one secret field; only one is supported.',
                    );
                }
                $seenSecretField = true;
                $secret = $value === null ? null : (string) $value;

                continue;
            }

            if ($target === 'connection' && $value !== null) {
                $connection[$fieldName] = $value;
            }
        }

        return [$connection, $secret];
    }
}
