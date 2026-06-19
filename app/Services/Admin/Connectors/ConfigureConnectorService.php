<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v8.17 — configure a **credential-based** connector (the first being IMAP)
 * entirely from the admin panel, mirroring the OAuth flow but driven by a
 * schema the connector advertises via {@see SupportsCredentialForm}.
 *
 * The whole thing is GENERIC — there is no `if ($name === 'imap')` branch. The
 * connector's `credentialFormSchema()` is the single source of truth for which
 * fields exist and where each value goes (the field `target`):
 *   - `secret`               → routed through `handleOAuthCallback()` → encrypted
 *                              vault; NEVER written to `config_json`.
 *   - `connection`           → `config_json['connection'][<name>]`.
 *   - `auth_mode`/`provider`/`config` (anything else) → `config_json[<name>]`
 *     (the IMAP connector reads `config_json['auth_mode']` /
 *     `config_json['xoauth2_provider']`, which is exactly the field `name`).
 *
 * Both auth modes reuse the connector's existing `initiateOAuth()` /
 * `handleOAuthCallback()` contract — no new connector method is invented:
 *   - **basic**: the host issues the connector's single-use OAuth state (via
 *     `initiateOAuth()`), then immediately drives `handleOAuthCallback()` with a
 *     synthetic request carrying `state` + the secret, so the connector pings the
 *     server and persists the credential. Success → ACTIVE; a
 *     {@see ConnectorAuthException} → the row stays PENDING with `error_json` and
 *     the exception propagates (the controller maps it to 422).
 *   - **xoauth2**: the host persists the config PENDING and returns the provider
 *     authorize URL from `initiateOAuth()`; the browser redirects and the EXISTING
 *     `oauth/callback` route finishes the flow. No change to that route.
 *
 * R30 — every `connector_installations` query is scoped to the active tenant;
 * the upsert rides the UNIQUE `(tenant_id, connector_name)` constraint.
 * Security — the secret is never persisted in `config_json`/logs/response; the
 * single-use state lifecycle is owned by the connector (issue → consume).
 */
final class ConfigureConnectorService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string,mixed>  $validated  Validated form payload keyed by field name.
     */
    public function configure(string $name, array $validated, int $createdBy): ConfigureConnectorResult
    {
        $connector = $this->registry->get($name);

        if ($connector === null) {
            throw new NotFoundHttpException("Connector '{$name}' is not registered.");
        }

        if (! $connector instanceof SupportsCredentialForm) {
            throw new NotFoundHttpException("Connector '{$name}' does not support credential configuration.");
        }

        [$config, $secret, $secretField] = $this->splitPayload($connector->credentialFormSchema(), $validated);

        if (array_key_exists('project_key', $validated) && $validated['project_key'] !== null) {
            $config['project_key'] = (string) $validated['project_key'];
        }

        $installation = $this->upsertPending($name, $config, $createdBy);

        $authMode = (string) ($config['auth_mode'] ?? 'basic');

        if ($authMode === 'xoauth2') {
            // Persist PENDING + hand the browser the provider authorize URL; the
            // existing oauth/callback route flips the row to ACTIVE on return.
            return new ConfigureConnectorResult(
                installation: $installation,
                redirectTo: $connector->initiateOAuth($installation->id),
            );
        }

        $this->completeBasicAuth($connector, $installation, $secretField ?? 'password', $secret);

        return new ConfigureConnectorResult($installation, redirectTo: null);
    }

    /**
     * Split the submitted values into the non-secret `config_json` payload, the
     * single secret (routed to the vault) and the secret field's name, strictly
     * by each field's target. Fields hidden by an unmet `showIf` condition are
     * skipped so they never pollute `config_json` (e.g. the basic-only
     * host/port/encryption keys are not written in xoauth2 mode).
     *
     * @param  list<array<string,mixed>>  $schema
     * @param  array<string,mixed>  $validated
     * @return array{0: array<string,mixed>, 1: ?string, 2: ?string}
     */
    private function splitPayload(array $schema, array $validated): array
    {
        // Effective value of every field (submitted value, else its default) so
        // a field's `showIf` can be evaluated against another field's resolved
        // value (e.g. auth_mode, which carries a 'basic' default).
        $effective = [];
        foreach ($schema as $field) {
            $name = (string) $field['name'];
            $effective[$name] = array_key_exists($name, $validated)
                ? $validated[$name]
                : ($field['default'] ?? null);
        }

        $config = [];
        $secret = null;
        $secretField = null;

        foreach ($schema as $field) {
            $fieldName = (string) $field['name'];
            $target = (string) $field['target'];

            $showIf = $field['showIf'] ?? null;
            if (is_array($showIf) && ($effective[$showIf['field']] ?? null) !== $showIf['equals']) {
                // Field is hidden for the submitted auth mode — do not persist it.
                continue;
            }

            $hasValue = array_key_exists($fieldName, $validated);
            if (! $hasValue && ($field['default'] ?? null) === null) {
                continue;
            }

            $value = $hasValue ? $validated[$fieldName] : $field['default'];

            if ($target === 'secret') {
                $secret = $value === null ? null : (string) $value;
                $secretField = $fieldName;

                continue;
            }

            if ($target === 'connection') {
                $config['connection'][$fieldName] = $value;

                continue;
            }

            // auth_mode / provider / config → top-level config_json key (the field
            // name already matches what the connector reads, e.g. 'auth_mode',
            // 'xoauth2_provider').
            $config[$fieldName] = $value;
        }

        return [$config, $secret, $secretField];
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function upsertPending(string $name, array $config, int $createdBy): ConnectorInstallation
    {
        $installation = ConnectorInstallation::query()
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $name)
            ->first();

        if ($installation === null) {
            return ConnectorInstallation::create([
                'tenant_id' => $this->tenantContext->current(),
                'connector_name' => $name,
                'config_json' => $config,
                'status' => ConnectorInstallation::STATUS_PENDING,
                'created_by' => $createdBy,
            ]);
        }

        // Re-configure: re-arm the single (tenant, name) row through the
        // PENDING → active round-trip and clear any prior error.
        $installation->forceFill([
            'config_json' => $config,
            'status' => ConnectorInstallation::STATUS_PENDING,
            'error_json' => null,
        ])->save();

        return $installation;
    }

    private function completeBasicAuth(
        SupportsCredentialForm $connector,
        ConnectorInstallation $installation,
        string $secretField,
        ?string $secret,
    ): void {
        // The connector issues a single-use state bound to this installation and
        // returns it embedded in its credential-form URL. We parse the state and
        // immediately replay it through handleOAuthCallback together with the
        // secret, so the connector verifies the login (ping) before persisting.
        // The secret is posted under its SCHEMA field name (the connector reads
        // it by that name) — keeping the flow generic for any credential connector.
        $url = $connector->initiateOAuth($installation->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = isset($query['state']) ? (string) $query['state'] : '';

        if ($state === '') {
            // The connector must embed a single-use state in its credential-form
            // URL; an empty one means a contract break — fail fast rather than
            // replaying an empty state into handleOAuthCallback().
            throw new ConnectorAuthException(
                "Connector '{$installation->connector_name}' returned no credential state to replay.",
            );
        }

        // Include the secret ONLY when one was actually submitted — fabricating an
        // empty string for a null secret is observably different from "missing" and
        // could persist an empty credential. When absent, the connector's own
        // missing-secret handling fires (a loud ConnectorAuthException → 422).
        $data = ['state' => $state];
        if ($secret !== null) {
            $data[$secretField] = $secret;
        }

        $synthetic = Request::create('/', 'POST', $data);

        try {
            $connector->handleOAuthCallback($installation->id, $synthetic);
        } catch (ConnectorAuthException $e) {
            $installation->forceFill([
                'status' => ConnectorInstallation::STATUS_PENDING,
                'error_json' => [
                    'message' => $e->getMessage(),
                    'recorded_at' => now()->toIso8601String(),
                ],
            ])->save();

            throw $e;
        }

        $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'error_json' => null,
        ])->save();
    }
}
