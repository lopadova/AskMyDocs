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
 *     server (the connection test) and persists the credential. Success → the row
 *     is kept ACTIVE; a {@see ConnectorAuthException} (or any failure during the
 *     test) → the just-created pending row is ROLLED BACK (deleted) and the
 *     exception propagates (the controller maps it to 422). Rolling back is what
 *     lets a corrected retry with the SAME label go through: a lingering pending
 *     row would trip the (tenant, connector, label) unique rule and leave the
 *     operator stuck on "label already taken" on every re-submit.
 *   - **xoauth2**: the host persists the config PENDING and returns the provider
 *     authorize URL from `initiateOAuth()`; the browser redirects and the EXISTING
 *     `oauth/callback` route finishes the flow. No change to that route.
 *
 * R30 — every `connector_installations` query is scoped to the active tenant;
 * the create rides the UNIQUE `(tenant_id, connector_name, label)` constraint
 * (v8.20 multi-account — N labelled accounts per connector per tenant).
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

        // v8.20 multi-account: `label` + `project_key` are first-class COLUMNS
        // (not config_json). project_key is the optional KB project binding;
        // null = the tenant default resolved by BaseConnector::resolveProjectKey().
        $label = (string) ($validated['label'] ?? 'default');
        $projectKey = isset($validated['project_key']) && $validated['project_key'] !== '' && $validated['project_key'] !== null
            ? (string) $validated['project_key']
            : null;

        $installation = $this->createPending($name, $label, $projectKey, $config, $createdBy);

        $authMode = (string) ($config['auth_mode'] ?? 'basic');

        if ($authMode === 'xoauth2') {
            // Persist PENDING + hand the browser the provider authorize URL; the
            // existing oauth/callback route flips the row to ACTIVE on return.
            // No synchronous connection test here — the browser round-trip IS the
            // test, so the PENDING row legitimately waits for the callback.
            return new ConfigureConnectorResult(
                installation: $installation,
                redirectTo: $connector->initiateOAuth($installation->id),
            );
        }

        // basic: test the connection (ping) NOW and keep the row ONLY if the login
        // succeeds. A failed test must leave NO trace — otherwise the orphaned
        // pending row's label trips the (tenant, connector, label) unique rule and
        // the operator can never retry after correcting a parameter (the "label
        // already taken" dead-end). Rolling back also stops a save-per-attempt leak
        // that would happen if that unique guard were ever relaxed.
        try {
            $this->completeBasicAuth($connector, $installation, $secretField ?? 'password', $secret);
        } catch (\Throwable $e) {
            // Hard delete (the model does not soft-delete) so the unique rule and
            // index are fully cleared for the retry; the companion
            // connector_credentials row cascades via its FK (R28) if the connector
            // wrote a partial secret before failing.
            if (! $installation->delete()) {
                throw new \RuntimeException('Failed to roll back the pending connector installation after a failed connection test.');
            }

            throw $e;
        }

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
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $effective[$name] = array_key_exists($name, $validated)
                ? $validated[$name]
                : ($field['default'] ?? null);
        }

        $config = [];
        $secret = null;
        $secretField = null;

        foreach ($schema as $field) {
            // Guard every schema key — a malformed connector schema must degrade,
            // not 500 with an undefined-index.
            $fieldName = (string) ($field['name'] ?? '');
            if ($fieldName === '') {
                continue;
            }
            $target = (string) ($field['target'] ?? '');

            $showIf = $field['showIf'] ?? null;
            if (is_array($showIf) && isset($showIf['field'], $showIf['equals'])
                && ($effective[$showIf['field']] ?? null) !== $showIf['equals']
            ) {
                // Field is hidden for the submitted auth mode — do not persist it.
                continue;
            }

            $hasValue = array_key_exists($fieldName, $validated);
            if (! $hasValue && ($field['default'] ?? null) === null) {
                continue;
            }

            $value = $hasValue ? $validated[$fieldName] : $field['default'];

            // Route to the vault on EITHER marker — target='secret' OR secret=true.
            // Keying on both (the same set ConfigureConnectorRequest masks in
            // $dontFlash) means a schema that sets secret=true but forgets
            // target=secret still never lands the credential in config_json.
            if ($target === 'secret' || ($field['secret'] ?? false) === true) {
                if ($secretField !== null) {
                    // Single-secret contract: a second secret field would silently
                    // drop the first. Fail fast so the limitation is explicit.
                    throw new \RuntimeException(
                        'Credential connector schema declares more than one secret field; only one is supported.',
                    );
                }
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
     * Create a NEW pending installation for the active tenant.
     *
     * v8.20 multi-account: configure always CREATES a fresh account keyed on
     * (tenant_id, connector_name, label). A duplicate label is rejected by
     * {@see ConfigureConnectorRequest}'s unique rule (friendly 422) and, in the
     * create-race window, by the DB unique `uq_connector_installations_tenant_name_label`
     * (the controller maps the resulting QueryException to 422). Re-authing an
     * existing account is NOT done here — it edits the row by id (see
     * ConnectorInstallationService); configure is strictly additive.
     *
     * @param  array<string,mixed>  $config
     */
    private function createPending(string $name, string $label, ?string $projectKey, array $config, int $createdBy): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $this->tenantContext->current(),
            'connector_name' => $name,
            'label' => $label,
            'project_key' => $projectKey,
            'config_json' => $config,
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $createdBy,
        ]);
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
        // secret, so the connector verifies the login (ping) before we keep the
        // row. The secret is posted under its SCHEMA field name (the connector
        // reads it by that name) — keeping the flow generic for any credential
        // connector. Any failure propagates to configure(), which rolls the
        // just-created pending row back so nothing is "saved" on a failed test.
        $url = $connector->initiateOAuth($installation->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = isset($query['state']) ? (string) $query['state'] : '';

        if ($state === '') {
            // The connector must embed a single-use state in its credential-form
            // URL; an empty one means a contract break. A ConnectorAuthException
            // makes configure() roll the pending row back like any other failed
            // test (and the controller maps it to 422).
            throw new ConnectorAuthException(
                "Connector '{$installation->connector_name}' returned no credential state to replay.",
            );
        }

        // Include the secret ONLY when one was actually submitted — fabricating an
        // empty string for a null secret is observably different from "missing"
        // and could persist an empty credential. When absent, the connector's own
        // missing-secret handling fires (loud → 422).
        $data = ['state' => $state];
        if ($secret !== null) {
            $data[$secretField] = $secret;
        }

        $connector->handleOAuthCallback($installation->id, Request::create('/', 'POST', $data));

        $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'error_json' => null,
        ])->save();
    }
}
