<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\EmailDatasetOperationNonce;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Atomic issue/consume boundary for destructive IMAP dataset operations.
 */
final readonly class EmailDatasetOperationConfirmation
{
    public function __construct(private int $ttlSeconds = 300)
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Confirmation TTL must be at least one second.');
        }
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public function issue(EmailDatasetOperationContext $context): array
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $argsHash = $this->argsHash($context);
        $payload = $context->canonicalPayload();
        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($this->ttlSeconds);

        DB::transaction(function () use (
            $argsHash,
            $context,
            $expiresAt,
            $now,
            $payload,
            $tokenHash,
        ): void {
            foreach ($payload['tenants'] as $tenantId) {
                EmailDatasetOperationNonce::query()->create([
                    'tenant_id' => $tenantId,
                    'token_hash' => $tokenHash,
                    'operation' => $context->operation,
                    'actor' => $context->actor,
                    'args_hash' => $argsHash,
                    'dataset_version' => $context->datasetVersion,
                    'manifest_checksum' => $context->manifestChecksum,
                    'selection_json' => $payload,
                    'created_at' => $now,
                    'expires_at' => $expiresAt,
                    'consumed_at' => null,
                ]);
            }
        });

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function consume(
        EmailDatasetOperationContext $context,
        ?string $token,
    ): void {
        if ($token === null || trim($token) === '') {
            throw new EmailDatasetConfirmationException(
                'missing',
                'L’operazione distruttiva richiede --confirm-token.',
            );
        }

        $tokenHash = hash('sha256', trim($token));
        $argsHash = $this->argsHash($context);
        $payload = $context->canonicalPayload();

        $failure = DB::transaction(function () use (
            $argsHash,
            $context,
            $payload,
            $tokenHash,
        ): ?string {
            $rows = [];
            foreach ($payload['tenants'] as $tenantId) {
                $row = EmailDatasetOperationNonce::query()
                    ->forTenant($tenantId)
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();
                if ($row === null) {
                    return 'invalid';
                }
                $rows[] = $row;
            }

            foreach ($rows as $row) {
                if ($row->consumed_at !== null) {
                    return 'used';
                }
                if ($row->expires_at->isPast()) {
                    return 'expired';
                }
                if ($row->operation !== $context->operation
                    || $row->actor !== $context->actor
                    || $row->args_hash !== $argsHash
                    || $row->dataset_version !== $context->datasetVersion
                    || $row->manifest_checksum !== $context->manifestChecksum
                    || $row->selection_json !== $payload) {
                    return 'scope_mismatch';
                }
            }

            $consumedAt = Carbon::now();
            foreach ($rows as $row) {
                $updated = EmailDatasetOperationNonce::query()
                    ->forTenant((string) $row->tenant_id)
                    ->whereKey($row->getKey())
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => $consumedAt]);
                if ($updated !== 1) {
                    throw new \RuntimeException('Confirmation token consumption was not persisted atomically.');
                }
            }

            return null;
        });

        if ($failure !== null) {
            throw new EmailDatasetConfirmationException(
                $failure,
                match ($failure) {
                    'used' => 'Il confirm token è già stato usato.',
                    'expired' => 'Il confirm token è scaduto.',
                    'scope_mismatch' => 'Il confirm token non corrisponde a operazione, dataset, selezione, attore o argomenti.',
                    default => 'Il confirm token non è valido per i tenant selezionati.',
                },
            );
        }
    }

    /**
     * @throws JsonException
     */
    private function argsHash(EmailDatasetOperationContext $context): string
    {
        return hash('sha256', json_encode(
            $context->canonicalPayload(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
