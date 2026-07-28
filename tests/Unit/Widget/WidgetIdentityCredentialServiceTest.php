<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\AdminCommandAudit;
use App\Models\User;
use App\Models\WidgetKey;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialConflict;
use App\Services\Widget\Exceptions\WidgetIdentityCredentialNotFound;
use App\Services\Widget\WidgetIdentityCredentialAudit;
use App\Services\Widget\WidgetIdentityCredentialService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class WidgetIdentityCredentialServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_rotate_and_disable_are_versioned_audited_and_never_audit_secrets(): void
    {
        $key = $this->key();
        $service = app(WidgetIdentityCredentialService::class);

        $enabled = $service->enable(
            $key->id,
            'default',
            0,
            null,
            WidgetIdentityCredentialService::SURFACE_CLI,
        );

        $this->assertStringStartsWith('ik_', $enabled->plainSecret);
        $this->assertTrue($enabled->key->user_auth_enabled);
        $this->assertSame(1, $enabled->key->identity_credential_version);
        $this->assertSame(0, $enabled->key->identity_access_epoch);
        $this->assertTrue(Hash::check($enabled->plainSecret, $enabled->key->identity_secret_hash));

        $rotated = $service->rotate(
            $key->id,
            'default',
            1,
            null,
            WidgetIdentityCredentialService::SURFACE_CLI,
        );

        $this->assertStringStartsWith('ik_', $rotated->plainSecret);
        $this->assertNotSame($enabled->plainSecret, $rotated->plainSecret);
        $this->assertFalse(Hash::check($enabled->plainSecret, $rotated->key->identity_secret_hash));
        $this->assertSame(2, $rotated->key->identity_credential_version);

        $disabled = $service->disable(
            $key->id,
            'default',
            2,
            null,
            WidgetIdentityCredentialService::SURFACE_CLI,
        );

        $this->assertFalse($disabled->key->user_auth_enabled);
        $this->assertNull($disabled->key->identity_secret_hash);
        $this->assertNull($disabled->plainSecret);
        $this->assertSame(3, $disabled->key->identity_credential_version);
        $this->assertSame(1, $disabled->key->identity_access_epoch);

        $audits = AdminCommandAudit::query()
            ->where('command', WidgetIdentityCredentialAudit::COMMAND)
            ->orderBy('id')
            ->get();
        $this->assertSame([
            'identity_auth_enabled',
            'identity_secret_created',
            'identity_secret_rotated',
            'identity_auth_disabled',
        ], $audits->pluck('args_json')->map(fn (array $args): string => $args['action'])->all());

        $auditPayload = $audits->pluck('args_json')->toJson();
        $this->assertStringNotContainsString('ik_', $auditPayload);
        $this->assertStringNotContainsString('identity_secret_hash', $auditPayload);
        $this->assertStringNotContainsString((string) $enabled->plainSecret, $auditPayload);
        $this->assertStringNotContainsString((string) $rotated->plainSecret, $auditPayload);
    }

    public function test_stale_version_is_rejected_without_mutating_the_credential(): void
    {
        $key = $this->key([
            'user_auth_enabled' => true,
            'identity_secret_hash' => Hash::make('ik_current'),
            'identity_credential_version' => 4,
        ]);
        $service = app(WidgetIdentityCredentialService::class);

        try {
            $service->rotate(
                $key->id,
                'default',
                3,
                null,
                WidgetIdentityCredentialService::SURFACE_CLI,
            );
            $this->fail('A stale writer must be rejected.');
        } catch (WidgetIdentityCredentialConflict $e) {
            $this->assertSame(3, $e->expectedVersion);
            $this->assertSame(4, $e->actualVersion);
        }

        $key->refresh();
        $this->assertSame(4, $key->identity_credential_version);
        $this->assertTrue(Hash::check('ik_current', $key->identity_secret_hash));
        $this->assertDatabaseHas('admin_command_audit', [
            'command' => WidgetIdentityCredentialAudit::COMMAND,
            'status' => AdminCommandAudit::STATUS_REJECTED,
            'error_message' => 'identity_credential_conflict',
        ]);
    }

    public function test_tenant_scope_hides_foreign_keys_and_audits_the_rejection(): void
    {
        $key = $this->key(['tenant_id' => 'tenant-a']);
        $service = app(WidgetIdentityCredentialService::class);

        $this->expectException(WidgetIdentityCredentialNotFound::class);
        try {
            $service->enable(
                $key->id,
                'tenant-b',
                0,
                null,
                WidgetIdentityCredentialService::SURFACE_CLI,
            );
        } finally {
            $this->assertFalse($key->fresh()->user_auth_enabled);
            $this->assertDatabaseHas('admin_command_audit', [
                'tenant_id' => 'tenant-b',
                'command' => WidgetIdentityCredentialAudit::COMMAND,
                'status' => AdminCommandAudit::STATUS_REJECTED,
                'error_message' => 'widget_key_not_found',
            ]);
        }
    }

    public function test_audit_failure_rolls_back_the_credential_mutation(): void
    {
        $key = $this->key();
        $failingAudit = new class extends WidgetIdentityCredentialAudit
        {
            public function completed(
                int $keyId,
                string $tenantId,
                string $projectKey,
                string $action,
                ?User $actor,
                string $surface,
                int $previousVersion,
                int $newVersion,
            ): AdminCommandAudit {
                throw new RuntimeException('audit unavailable');
            }
        };
        $service = new WidgetIdentityCredentialService(
            $failingAudit,
            app(Gate::class),
        );

        try {
            $service->enable(
                $key->id,
                'default',
                0,
                null,
                WidgetIdentityCredentialService::SURFACE_CLI,
            );
            $this->fail('Mutation must fail closed when the audit cannot be persisted.');
        } catch (RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }

        $key->refresh();
        $this->assertFalse($key->user_auth_enabled);
        $this->assertNull($key->identity_secret_hash);
        $this->assertSame(0, $key->identity_credential_version);
    }

    public function test_status_never_returns_plaintext(): void
    {
        $key = $this->key([
            'user_auth_enabled' => true,
            'identity_secret_hash' => Hash::make('ik_hidden'),
            'identity_credential_version' => 7,
        ]);

        $result = app(WidgetIdentityCredentialService::class)->status($key->id, 'default');

        $this->assertNull($result->plainSecret);
        $this->assertSame(7, $result->key->identity_credential_version);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function key(array $overrides = []): WidgetKey
    {
        static $sequence = 0;
        $sequence++;

        return WidgetKey::query()->create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs',
            'public_key' => 'pk_identity_service_'.$sequence,
            'secret_hash' => Hash::make('sk_proxy'),
            'identity_secret_hash' => null,
            'identity_credential_version' => 0,
            'identity_access_epoch' => 0,
            'label' => 'Identity service '.$sequence,
            'allowed_origins' => ['https://host.example'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'user_auth_enabled' => false,
            'is_active' => true,
        ], $overrides));
    }
}
