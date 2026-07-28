<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Widget\Exceptions\WidgetIdentityCredentialConflict;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialDisabled;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialNotFound;
use App\Services\Widget\WidgetIdentityCredentialService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface over the same audited core used by the admin HTTP API.
 *
 * Shell access is the authorization boundary. MCP mutation is intentionally
 * absent because an ik_ secret must never enter an agent transcript.
 */
final class WidgetIdentityCredentialCommand extends Command
{
    protected $signature = 'widget:identity-credential
                            {action : status|enable|disable|rotate}
                            {key : Widget key numeric id}
                            {--tenant=default : Tenant that owns the widget key}
                            {--expected-version= : Required for enable, disable and rotate}
                            {--force : Skip the interactive mutation confirmation}';

    protected $description = 'Inspect or mutate an audited widget identity credential.';

    public function handle(
        WidgetIdentityCredentialService $credentials,
        TenantContext $tenants,
    ): int {
        $action = strtolower((string) $this->argument('action'));
        $keyId = filter_var($this->argument('key'), FILTER_VALIDATE_INT);
        $tenantId = trim((string) $this->option('tenant'));

        if (! in_array($action, ['status', 'enable', 'disable', 'rotate'], true)) {
            $this->error('Action must be one of: status, enable, disable, rotate.');

            return self::INVALID;
        }
        if (! is_int($keyId) || $keyId < 1 || $tenantId === '') {
            $this->error('A positive numeric key id and a non-empty tenant are required.');

            return self::INVALID;
        }

        $previousTenant = $tenants->current();
        $tenants->set($tenantId);

        try {
            if ($action === 'status') {
                $result = $credentials->status($keyId, $tenantId);
                $this->table(
                    ['Key', 'Tenant', 'Project', 'Enabled', 'Version'],
                    [[
                        $result->key->id,
                        $result->key->tenant_id,
                        $result->key->project_key,
                        $result->key->user_auth_enabled ? 'yes' : 'no',
                        $result->key->identity_credential_version,
                    ]],
                );

                return self::SUCCESS;
            }

            $version = filter_var($this->option('expected-version'), FILTER_VALIDATE_INT);
            if (! is_int($version) || $version < 0) {
                $this->error('--expected-version is required for mutations and must be zero or greater.');

                return self::INVALID;
            }
            if (! $this->option('force') && ! $this->confirm(
                sprintf('%s identity credentials for widget key %d?', ucfirst($action), $keyId),
            )) {
                $this->warn('No changes made.');

                return self::FAILURE;
            }

            $result = match ($action) {
                'enable' => $credentials->enable(
                    $keyId,
                    $tenantId,
                    $version,
                    null,
                    WidgetIdentityCredentialService::SURFACE_CLI,
                ),
                'disable' => $credentials->disable(
                    $keyId,
                    $tenantId,
                    $version,
                    null,
                    WidgetIdentityCredentialService::SURFACE_CLI,
                ),
                'rotate' => $credentials->rotate(
                    $keyId,
                    $tenantId,
                    $version,
                    null,
                    WidgetIdentityCredentialService::SURFACE_CLI,
                ),
            };

            $this->info(sprintf(
                'Identity credential %s; version is now %d.',
                $action === 'disable' ? 'disabled' : $action.'d',
                $result->key->identity_credential_version,
            ));
            if ($result->plainSecret !== null) {
                $this->warn('Store this server-only credential securely. It will not be shown again.');
                $this->line('Identity secret: <fg=yellow>'.$result->plainSecret.'</>');
            }

            return self::SUCCESS;
        } catch (WidgetIdentityCredentialConflict $e) {
            $this->error(sprintf(
                'Credential version conflict: expected %d, current %d. Run status and retry.',
                $e->expectedVersion,
                $e->actualVersion,
            ));

            return self::FAILURE;
        } catch (WidgetIdentityCredentialDisabled|WidgetIdentityCredentialNotFound $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('Identity credential operation failed.');

            return self::FAILURE;
        } finally {
            $tenants->set($previousTenant);
        }
    }
}
