<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Admin\Connectors\ConfigureConnectorService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * v8.20 — PHP write surface (R44) for installing a CREDENTIAL connector account
 * (the first being IMAP) from the CLI, with the secret entered via an
 * interactive masked prompt (never a flag — shell history / process list safe).
 *
 * Delegates the actual credential round-trip to the SAME core the HTTP
 * `configure` endpoint uses ({@see ConfigureConnectorService}). Scope: basic-auth
 * credential connectors. OAuth connectors (browser redirect) and the xoauth2
 * auth-mode are not CLI-completable — use the admin UI for those.
 *
 * Multi-account: `--label` names the account; a duplicate label for the same
 * (tenant, connector) is rejected (the DB unique). `--project` optionally binds
 * the account to a real tenant project; absent = the tenant default.
 */
final class ConnectorsInstallCommand extends Command
{
    protected $signature = 'connectors:install
                            {connector : Connector key (e.g. imap)}
                            {--tenant=default : Tenant to install the account under}
                            {--label=default : Account label (unique per tenant+connector)}
                            {--project= : Bind to this project_key (must exist for the tenant); empty = tenant default}
                            {--set=* : Non-secret field overrides as name=value (repeatable; the rest are prompted)}
                            {--created-by=0 : User id to record as creator (0 = system/CLI)}';

    protected $description = 'Install a credential-based connector account (interactive secret prompt) for a tenant.';

    public function handle(
        ConnectorRegistry $registry,
        ConfigureConnectorService $service,
        TenantContext $tenants,
    ): int {
        $name = (string) $this->argument('connector');
        $connector = $registry->get($name);

        if ($connector === null) {
            $this->error("Connector '{$name}' is not registered.");

            return self::FAILURE;
        }

        if (! $connector instanceof SupportsCredentialForm) {
            $this->error("Connector '{$name}' is OAuth-based — install it from the admin UI (browser redirect), not the CLI.");

            return self::FAILURE;
        }

        $tenant = (string) $this->option('tenant');
        $previous = $tenants->current();
        $tenants->set($tenant);

        try {
            return $this->install($connector, $service, $name, $tenant);
        } finally {
            $tenants->set($previous);
        }
    }

    private function install(
        SupportsCredentialForm $connector,
        ConfigureConnectorService $service,
        string $name,
        string $tenant,
    ): int {
        $project = $this->resolveProject($tenant);
        if ($project === false) {
            return self::FAILURE;
        }

        $overrides = $this->parseSetOptions();

        // CLI scope: basic auth only (xoauth2 needs a browser).
        $values = ['auth_mode' => 'basic'];

        foreach ($connector->credentialFormSchema() as $field) {
            $fieldName = (string) ($field['name'] ?? '');
            if ($fieldName === '' || $fieldName === 'auth_mode') {
                continue;
            }

            if ($this->isFieldHidden($field, $values)) {
                continue;
            }

            $values[$fieldName] = $this->valueForField($field, $fieldName, $overrides);
        }

        $values['label'] = (string) $this->option('label');
        if ($project !== null) {
            $values['project_key'] = $project;
        }

        try {
            $result = $service->configure($name, $values, (int) $this->option('created-by'));
        } catch (ConnectorAuthException $e) {
            $this->error("Credential verification failed: {$e->getMessage()}");

            return self::FAILURE;
        } catch (QueryException $e) {
            // R14: gate on SQLSTATE first (23000 = MySQL/SQLite integrity, 23505 = Postgres
            // unique) before message inspection so an unrelated schema or FK error is never
            // misclassified as a duplicate-label 422. Message then narrows to the specific
            // label constraint: named index (Postgres/MySQL) or SQLite column-list form.
            $isDuplicateLabel = in_array($e->errorInfo[0] ?? '', ['23000', '23505'], true)
                && (str_contains($e->getMessage(), 'uq_connector_installations_tenant_name_label')
                    || str_contains($e->getMessage(), 'connector_installations.label'));

            if ($isDuplicateLabel) {
                $this->error("An account with label '{$this->option('label')}' already exists for connector '{$name}' on tenant '{$tenant}'.");

                return self::FAILURE;
            }

            throw $e;
        }

        $installation = $result->installation;
        $this->info(sprintf(
            "Installed '%s' account '%s' (id %d) — status %s%s.",
            $name,
            $installation->label,
            $installation->id,
            $installation->status,
            $installation->project_key !== null ? ", project {$installation->project_key}" : ' (tenant default project)',
        ));

        return $installation->status === ConnectorInstallation::STATUS_ACTIVE ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return string|null|false  project_key, null (tenant default), or false on
     *                            an invalid --project (error already printed).
     */
    private function resolveProject(string $tenant): string|null|false
    {
        $project = $this->option('project');
        if ($project === null || $project === '') {
            return null;
        }

        $exists = Project::query()
            ->where('tenant_id', $tenant)
            ->where('project_key', $project)
            ->exists();

        if (! $exists) {
            $this->error("Project '{$project}' does not exist for tenant '{$tenant}'.");

            return false;
        }

        return (string) $project;
    }

    /**
     * @param  array<string,mixed>  $field
     * @param  array<string,string>  $overrides
     */
    private function valueForField(array $field, string $fieldName, array $overrides): mixed
    {
        $label = (string) ($field['label'] ?? $fieldName);
        $type = (string) ($field['type'] ?? 'text');

        // The secret is never accepted via --set; always a masked prompt
        // ($this->secret hides the input — shell-history / process-list safe).
        if (($field['target'] ?? null) === 'secret' || ($field['secret'] ?? false) === true) {
            return $this->secret($label);
        }

        if ($type === 'checkbox') {
            return array_key_exists($fieldName, $overrides)
                ? filter_var($overrides[$fieldName], FILTER_VALIDATE_BOOLEAN)
                : $this->confirm($label, (bool) ($field['default'] ?? false));
        }

        if (array_key_exists($fieldName, $overrides)) {
            return $type === 'number' ? (int) $overrides[$fieldName] : $overrides[$fieldName];
        }

        if ($type === 'select') {
            $options = array_keys((array) ($field['options'] ?? []));

            return $this->choice($label, $options, $field['default'] ?? null);
        }

        $answer = $this->ask($label, $field['default'] ?? null);

        return $type === 'number' ? (int) $answer : $answer;
    }

    /**
     * @param  array<string,mixed>  $field
     * @param  array<string,mixed>  $values
     */
    private function isFieldHidden(array $field, array $values): bool
    {
        $showIf = $field['showIf'] ?? null;

        return is_array($showIf)
            && isset($showIf['field'], $showIf['equals'])
            && ($values[$showIf['field']] ?? null) !== $showIf['equals'];
    }

    /**
     * @return array<string,string>
     */
    private function parseSetOptions(): array
    {
        $out = [];
        foreach ((array) $this->option('set') as $pair) {
            $pair = (string) $pair;
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            if ($key !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
