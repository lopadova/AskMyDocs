<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\User;
use App\Models\WidgetKey;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialConflict;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialDisabled;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialNotFound;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialUnauthorized;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared lifecycle boundary for the server-only ik_ credential.
 *
 * R44 exception: mutation parity is deliberately PHP + HTTP only. An MCP
 * mutation would put a one-time server credential in an agent transcript.
 * Agent-facing integrations may expose status metadata, never plaintext.
 */
final class WidgetIdentityCredentialService
{
    public const SURFACE_HTTP = 'http';

    public const SURFACE_CLI = 'cli';

    public function __construct(
        private readonly WidgetIdentityCredentialAudit $audit,
        private readonly Gate $gate,
    ) {}

    public function status(int $keyId, string $tenantId): WidgetIdentityCredentialResult
    {
        $key = WidgetKey::query()
            ->forTenant($tenantId)
            ->whereKey($keyId)
            ->first();

        if ($key === null) {
            throw new WidgetIdentityCredentialNotFound;
        }

        return new WidgetIdentityCredentialResult($key);
    }

    public function enable(
        int $keyId,
        string $tenantId,
        int $expectedVersion,
        ?User $actor,
        string $surface,
    ): WidgetIdentityCredentialResult {
        return $this->mutate(
            keyId: $keyId,
            tenantId: $tenantId,
            expectedVersion: $expectedVersion,
            actor: $actor,
            surface: $surface,
            action: 'enable',
        );
    }

    public function disable(
        int $keyId,
        string $tenantId,
        int $expectedVersion,
        ?User $actor,
        string $surface,
    ): WidgetIdentityCredentialResult {
        return $this->mutate(
            keyId: $keyId,
            tenantId: $tenantId,
            expectedVersion: $expectedVersion,
            actor: $actor,
            surface: $surface,
            action: 'disable',
        );
    }

    public function rotate(
        int $keyId,
        string $tenantId,
        int $expectedVersion,
        ?User $actor,
        string $surface,
    ): WidgetIdentityCredentialResult {
        return $this->mutate(
            keyId: $keyId,
            tenantId: $tenantId,
            expectedVersion: $expectedVersion,
            actor: $actor,
            surface: $surface,
            action: 'rotate',
        );
    }

    private function mutate(
        int $keyId,
        string $tenantId,
        int $expectedVersion,
        ?User $actor,
        string $surface,
        string $action,
    ): WidgetIdentityCredentialResult {
        $this->assertSurfaceAuthorization(
            keyId: $keyId,
            tenantId: $tenantId,
            actor: $actor,
            surface: $surface,
            action: $action,
        );

        $outcome = DB::transaction(function () use (
            $keyId,
            $tenantId,
            $expectedVersion,
            $actor,
            $surface,
            $action,
        ): WidgetIdentityCredentialResult|RuntimeException {
            $key = WidgetKey::query()
                ->forTenant($tenantId)
                ->whereKey($keyId)
                ->lockForUpdate()
                ->first();

            if ($key === null) {
                $this->audit->rejected(
                    keyId: $keyId,
                    tenantId: $tenantId,
                    projectKey: null,
                    action: $action,
                    actor: $actor,
                    surface: $surface,
                    reason: 'widget_key_not_found',
                    expectedVersion: $expectedVersion,
                );

                return new WidgetIdentityCredentialNotFound;
            }

            $actualVersion = (int) $key->identity_credential_version;
            if ($actualVersion !== $expectedVersion) {
                $this->audit->rejected(
                    keyId: $keyId,
                    tenantId: $tenantId,
                    projectKey: $key->project_key,
                    action: $action,
                    actor: $actor,
                    surface: $surface,
                    reason: 'identity_credential_conflict',
                    expectedVersion: $expectedVersion,
                    actualVersion: $actualVersion,
                );

                return new WidgetIdentityCredentialConflict($expectedVersion, $actualVersion);
            }

            if ($action === 'rotate' && ! $key->user_auth_enabled) {
                $this->audit->rejected(
                    keyId: $keyId,
                    tenantId: $tenantId,
                    projectKey: $key->project_key,
                    action: $action,
                    actor: $actor,
                    surface: $surface,
                    reason: 'user_auth_disabled',
                    expectedVersion: $expectedVersion,
                    actualVersion: $actualVersion,
                );

                return new WidgetIdentityCredentialDisabled;
            }

            $plainSecret = null;
            $newVersion = $actualVersion;
            $auditActions = [];

            if ($action === 'enable' && ! $key->user_auth_enabled) {
                $plainSecret = $this->newSecret();
                $newVersion++;
                $key->forceFill([
                    'user_auth_enabled' => true,
                    'identity_secret_hash' => Hash::make($plainSecret),
                    'identity_credential_version' => $newVersion,
                ])->save();
                $auditActions = ['identity_auth_enabled', 'identity_secret_created'];
            } elseif ($action === 'disable' && $key->user_auth_enabled) {
                $newVersion++;
                $key->forceFill([
                    'user_auth_enabled' => false,
                    'identity_secret_hash' => null,
                    'identity_credential_version' => $newVersion,
                    'identity_access_epoch' => ((int) $key->identity_access_epoch) + 1,
                ])->save();
                $auditActions = ['identity_auth_disabled'];
            } elseif ($action === 'rotate') {
                $plainSecret = $this->newSecret();
                $newVersion++;
                $key->forceFill([
                    'identity_secret_hash' => Hash::make($plainSecret),
                    'identity_credential_version' => $newVersion,
                ])->save();
                $auditActions = ['identity_secret_rotated'];
            }

            foreach ($auditActions as $auditAction) {
                $this->audit->completed(
                    keyId: $keyId,
                    tenantId: $tenantId,
                    projectKey: $key->project_key,
                    action: $auditAction,
                    actor: $actor,
                    surface: $surface,
                    previousVersion: $actualVersion,
                    newVersion: $newVersion,
                );
            }

            return new WidgetIdentityCredentialResult($key->fresh(), $plainSecret);
        });

        if ($outcome instanceof RuntimeException) {
            throw $outcome;
        }

        return $outcome;
    }

    private function assertSurfaceAuthorization(
        int $keyId,
        string $tenantId,
        ?User $actor,
        string $surface,
        string $action,
    ): void {
        $authorized = $surface === self::SURFACE_CLI
            || ($surface === self::SURFACE_HTTP
                && $actor !== null
                && $this->gate->forUser($actor)->allows('manageWidgetKeys'));

        if ($authorized) {
            return;
        }

        $this->audit->rejected(
            keyId: $keyId,
            tenantId: $tenantId,
            projectKey: null,
            action: $action,
            actor: $actor,
            surface: $surface,
            reason: 'identity_credential_unauthorized',
        );

        throw new WidgetIdentityCredentialUnauthorized;
    }

    private function newSecret(): string
    {
        return 'ik_'.Str::random(48);
    }
}
