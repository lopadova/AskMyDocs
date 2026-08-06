<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\AdminCommandAudit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-mailbox forensic lifecycle. Arguments are an explicit identifier-only
 * allowlist: no credentials, message content or mailbox addresses.
 */
final readonly class EmailDatasetOperationAudit
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return array<string, array{id: int, tenant_id: string}>
     */
    public function begin(EmailDatasetOperationContext $context): array
    {
        return DB::transaction(function () use ($context): array {
            $handles = [];
            foreach ($context->canonicalPayload()['mailboxes'] as $mailboxKey) {
                $tenantId = $context->tenantByMailbox[$mailboxKey];
                $handles[$mailboxKey] = [
                    'id' => $this->create($context, $mailboxKey, $tenantId, AdminCommandAudit::STATUS_STARTED)->id,
                    'tenant_id' => $tenantId,
                ];
            }

            return $handles;
        });
    }

    public function reject(EmailDatasetOperationContext $context, string $reason): void
    {
        foreach ($context->canonicalPayload()['mailboxes'] as $mailboxKey) {
            $tenantId = $context->tenantByMailbox[$mailboxKey];
            $this->create(
                $context,
                $mailboxKey,
                $tenantId,
                AdminCommandAudit::STATUS_REJECTED,
                $reason,
            );
        }
    }

    /**
     * @param  array<string, array{id: int, tenant_id: string}>  $handles
     * @param  array<string, array{appended: int, purged: int}>  $results
     */
    public function complete(array $handles, array $results): void
    {
        DB::transaction(function () use ($handles, $results): void {
            foreach ($handles as $mailboxKey => $handle) {
                $result = $results[$mailboxKey] ?? ['appended' => 0, 'purged' => 0];
                $this->finish(
                    $handle,
                    AdminCommandAudit::STATUS_COMPLETED,
                    sprintf('appended=%d; purged=%d', $result['appended'], $result['purged']),
                    null,
                );
            }
        });
    }

    /**
     * @param  array<string, array{id: int, tenant_id: string}>  $handles
     */
    public function fail(array $handles, Throwable $exception): void
    {
        DB::transaction(function () use ($exception, $handles): void {
            foreach ($handles as $handle) {
                $this->finish(
                    $handle,
                    AdminCommandAudit::STATUS_FAILED,
                    null,
                    mb_substr($exception->getMessage(), 0, 1000),
                );
            }
        });
    }

    private function create(
        EmailDatasetOperationContext $context,
        string $mailboxKey,
        string $tenantId,
        string $status,
        ?string $error = null,
    ): AdminCommandAudit {
        $previousTenant = $this->tenantContext->current();
        try {
            $this->tenantContext->set($tenantId);

            return AdminCommandAudit::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => null,
                'command' => 'mail.seed-imap.'.$context->operation,
                'args_json' => [
                    'surface' => 'cli',
                    'mailbox_key' => $mailboxKey,
                    'operation' => $context->operation,
                    'actor' => $context->actor,
                    'dataset_version' => $context->datasetVersion,
                    'manifest_checksum' => $context->manifestChecksum,
                    'selection' => $context->canonicalPayload()['mailboxes'],
                ],
                'status' => $status,
                'exit_code' => $status === AdminCommandAudit::STATUS_REJECTED ? 1 : null,
                'stdout_head' => null,
                'error_message' => $error === null ? null : mb_substr($error, 0, 1000),
                'started_at' => now(),
                'completed_at' => $status === AdminCommandAudit::STATUS_STARTED ? null : now(),
                'client_ip' => null,
                'user_agent' => 'artisan',
            ]);
        } finally {
            $this->tenantContext->set($previousTenant);
        }
    }

    /**
     * @param  array{id: int, tenant_id: string}  $handle
     */
    private function finish(
        array $handle,
        string $status,
        ?string $stdout,
        ?string $error,
    ): void {
        $updated = AdminCommandAudit::query()
            ->forTenant($handle['tenant_id'])
            ->whereKey($handle['id'])
            ->where('status', AdminCommandAudit::STATUS_STARTED)
            ->update([
                'status' => $status,
                'exit_code' => $status === AdminCommandAudit::STATUS_COMPLETED ? 0 : 1,
                'stdout_head' => $stdout,
                'error_message' => $error,
                'completed_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new \RuntimeException(
                "Unable to finalize e-mail dataset audit {$handle['id']}.",
            );
        }
    }
}
