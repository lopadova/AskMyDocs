<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\WidgetKey;
use App\Services\Widget\WidgetIntroService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Read the resolved pre-conversation welcome card for a widget key in the current tenant. Returns structured content only; no secrets. Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
final class WidgetIntroConfigTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'widget_key_id' => $schema->integer()
                ->description('Numeric widget_keys.id to inspect in the current tenant.')
                ->required(),
        ];
    }

    public function handle(Request $request, WidgetIntroService $intro, TenantContext $tenants): Response
    {
        $id = (int) $request->get('widget_key_id');
        $key = WidgetKey::query()->forTenant($tenants->current())->whereKey($id)->first();
        if (! $key instanceof WidgetKey) {
            return Response::json(['error' => "Widget key {$id} not found for this tenant."]);
        }

        return Response::json([
            'widget_key_id' => (int) $key->id,
            'label' => $key->label,
            'project_key' => $key->project_key,
            'intro' => $intro->resolve($key->intro_config),
        ]);
    }
}
