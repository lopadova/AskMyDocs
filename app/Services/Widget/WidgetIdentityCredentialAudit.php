<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\AdminCommandAudit;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Append-only audit adapter for identity-credential mutations.
 *
 * The allowlisted payload must never receive plaintext credentials or hashes.
 * Keeping this adapter beside the shared service gives HTTP and CLI the same
 * durable forensic contract.
 */
class WidgetIdentityCredentialAudit
{
    public const COMMAND = 'widget.identity-credential';

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
        return $this->record(
            keyId: $keyId,
            tenantId: $tenantId,
            projectKey: $projectKey,
            action: $action,
            actor: $actor,
            surface: $surface,
            status: AdminCommandAudit::STATUS_COMPLETED,
            previousVersion: $previousVersion,
            newVersion: $newVersion,
        );
    }

    public function rejected(
        int $keyId,
        string $tenantId,
        ?string $projectKey,
        string $action,
        ?User $actor,
        string $surface,
        string $reason,
        ?int $expectedVersion = null,
        ?int $actualVersion = null,
    ): AdminCommandAudit {
        return $this->record(
            keyId: $keyId,
            tenantId: $tenantId,
            projectKey: $projectKey,
            action: $action,
            actor: $actor,
            surface: $surface,
            status: AdminCommandAudit::STATUS_REJECTED,
            expectedVersion: $expectedVersion,
            actualVersion: $actualVersion,
            reason: $reason,
        );
    }

    private function record(
        int $keyId,
        string $tenantId,
        ?string $projectKey,
        string $action,
        ?User $actor,
        string $surface,
        string $status,
        ?int $previousVersion = null,
        ?int $newVersion = null,
        ?int $expectedVersion = null,
        ?int $actualVersion = null,
        ?string $reason = null,
    ): AdminCommandAudit {
        $args = array_filter([
            'widget_key_id' => $keyId,
            'tenant' => $tenantId,
            'project' => $projectKey,
            'action' => $action,
            'actor_id' => $actor?->id,
            'surface' => $surface,
            'previous_version' => $previousVersion,
            'new_version' => $newVersion,
            'expected_version' => $expectedVersion,
            'actual_version' => $actualVersion,
        ], static fn (mixed $value): bool => $value !== null);

        return AdminCommandAudit::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $actor?->id,
            'command' => self::COMMAND,
            'args_json' => $args,
            'status' => $status,
            'exit_code' => $status === AdminCommandAudit::STATUS_COMPLETED ? 0 : null,
            'error_message' => $reason,
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
        ]);
    }
}
