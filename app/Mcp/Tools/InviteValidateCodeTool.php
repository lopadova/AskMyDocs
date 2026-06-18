<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Invite\CodeValidator;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP read surface (R44, third surface) over the SAME CodeValidator the HTTP
 * and PHP layers use. Advisory only — writes nothing. Tenant is the
 * MCP-resolved tenant (R30); the error string is the canonical lowercase code.
 */
#[Description('Validate an invite code without redeeming it. Returns whether the code is currently redeemable, or the canonical error (invalid/expired/exhausted/revoked/ineligible).')]
#[IsReadOnly]
#[IsIdempotent]
class InviteValidateCodeTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()
                ->description('The invite code to validate (any human casing / separators — it is normalized).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $code = (string) $request->get('code');
        if ($code === '') {
            return Response::json(['error' => 'invalid']);
        }

        $result = app(CodeValidator::class)->validate($code, app(TenantContext::class)->current());

        if (! $result->ok) {
            return Response::json(['valid' => false, 'error' => $result->error->value]);
        }

        return Response::json([
            'valid' => true,
            'code_kind' => $result->code->code_kind,
            'max_uses' => $result->code->max_uses,
            'current_uses' => $result->code->current_uses,
        ]);
    }
}
