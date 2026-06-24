<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\AdminCommandAudit;
use App\Services\Kb\Pii\SubjectErasureService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.23 (Ciclo 4) — MCP surface (R44) for GDPR Art.17 right-to-erasure, over the
 * SAME {@see SubjectErasureService} core as the HTTP endpoint and the
 * `kb:erase-subject` CLI.
 *
 * A destructive, irreversible write, so doubly gated — and NOT annotated
 * `#[IsReadOnly]`, so the host `McpToolAuthorizerAdapter` treats it as a write
 * tool requiring super-admin, AND the tool additionally requires the
 * `pii.erase` permission (dpo / super-admin). Net allow-set over MCP:
 * super-admin only. Tenant-scoped (R30); every completed erasure and every
 * permission-denied attempt writes an `admin_command_audit` row.
 */
#[Description('GDPR Art.17 right-to-erasure: crypto-shred a subject\'s reversible token-vault entries (by their PII value, e.g. an email) in the caller\'s tenant, so the surrogates left in the KB become permanently unresolvable. Destructive + irreversible. Requires the pii.erase permission (super-admin); audited.')]
class KbEraseSubjectTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'values' => $schema->array()
                ->items($schema->string())
                ->description('One or more PII value(s) identifying the subject (e.g. an email) to crypto-shred from the tenant vault.')
                ->required(),
        ];
    }

    public function handle(Request $request, SubjectErasureService $eraser, TenantContext $tenants): Response
    {
        $permission = (string) config('kb.pii_redactor.erase_permission', 'pii.erase');
        $user = auth()->user();
        $context = [
            'client_ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        $rawValues = $request->get('values');
        $values = is_array($rawValues) ? array_values(array_filter($rawValues, 'is_string')) : [];

        $hasPermission = $user !== null && method_exists($user, 'can') && $user->can($permission);
        if (! $hasPermission) {
            $eraser->audit(
                AdminCommandAudit::STATUS_REJECTED,
                $user?->id,
                ['value_count' => count($values), 'surface' => 'mcp'],
                $context,
                "Missing permission: {$permission}",
            );

            return Response::error("Forbidden: missing {$permission} permission.");
        }

        if ($values === []) {
            return Response::error('Provide at least one PII value to erase.');
        }

        $tenantId = $tenants->current();
        $erased = $eraser->eraseValues($tenantId, $values);

        $eraser->audit(
            AdminCommandAudit::STATUS_COMPLETED,
            $user?->id,
            ['value_count' => count($values), 'erased' => $erased, 'surface' => 'mcp'],
            $context,
        );

        return Response::json([
            'tenant_id' => $tenantId,
            'value_count' => count($values),
            'erased' => $erased,
        ]);
    }
}
