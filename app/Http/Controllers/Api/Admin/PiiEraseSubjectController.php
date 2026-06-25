<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\EraseSubjectRequest;
use App\Models\AdminCommandAudit;
use App\Services\Kb\Pii\SubjectErasureService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * v8.23 (Ciclo 4) — HTTP surface for GDPR Art.17 right-to-erasure: crypto-shred
 * a subject's reversible token-vault entries in the active tenant.
 *
 * POST /api/admin/pii/erase-subject  { "values": ["mario@example.com", ...] }
 *
 * Gated by `config('kb.pii_redactor.erase_permission')` (default `pii.erase` —
 * dpo / super-admin); without it the action returns 403. The lookup + delete are
 * tenant-scoped (R30) — a value is shredded ONLY in the caller's tenant. Every
 * completed erasure and every 403 rejection writes an `admin_command_audit` row
 * (`command='pii.erase'`).
 */
final class PiiEraseSubjectController extends Controller
{
    public function __construct(
        private readonly SubjectErasureService $eraser,
        private readonly TenantContext $tenant,
    ) {}

    public function erase(EraseSubjectRequest $request): JsonResponse
    {
        $permission = (string) config('kb.pii_redactor.erase_permission', 'pii.erase');
        $user = $request->user();
        // Normalise (trim + de-dup) up front so the response + audit `value_count`
        // reflect the EFFECTIVE request the service acts on, not the raw payload.
        $values = $this->eraser->normalizeValues($request->validated()['values']);
        $context = [
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        $hasPermission = $user !== null && method_exists($user, 'can') && $user->can($permission);
        if (! $hasPermission) {
            $this->eraser->audit(
                AdminCommandAudit::STATUS_REJECTED,
                $user?->id,
                ['value_count' => count($values), 'surface' => 'http'],
                $context,
                "Missing permission: {$permission}",
            );

            return response()->json([
                'message' => "Forbidden: missing {$permission} permission.",
            ], Response::HTTP_FORBIDDEN);
        }

        $tenantId = $this->tenant->current();
        $erased = $this->eraser->eraseValues($tenantId, $values);

        // Never echo the submitted PII values back in the audit args — record
        // only the count, so the forensic trail itself does not become a PII sink.
        $this->eraser->audit(
            AdminCommandAudit::STATUS_COMPLETED,
            $user?->id,
            ['value_count' => count($values), 'erased' => $erased, 'surface' => 'http'],
            $context,
        );

        return response()->json([
            'tenant_id' => $tenantId,
            'value_count' => count($values),
            'erased' => $erased,
        ]);
    }
}
