<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Resolve a public widget session inside every ownership boundary established
 * by the widget credential: tenant, key, project and optional host identity.
 */
final class WidgetSessionResolver
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function find(Request $request, string $publicId): ?WidgetSession
    {
        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);
        if (! $key instanceof WidgetKey || $key->tenant_id !== $this->tenants->current()) {
            return null;
        }

        $identity = $request->attributes->get(ResolveWidgetKey::ATTR_IDENTITY);
        if ($identity !== null && (! $identity instanceof WidgetIdentity
            || $identity->tenant_id !== $key->tenant_id
            || $identity->widget_key_id !== $key->id
            || $identity->project_key !== $key->project_key)) {
            return null;
        }

        $query = WidgetSession::query()
            ->forTenant($this->tenants->current())
            ->where('public_session_id', $publicId)
            ->where('widget_key_id', $key->id)
            ->where('project_key', $key->project_key);

        $identity instanceof WidgetIdentity
            ? $query->where('widget_identity_id', $identity->id)
            : $query->whereNull('widget_identity_id');

        return $query->first();
    }

    public function findOrFail(Request $request, string $publicId): WidgetSession
    {
        $session = $this->find($request, $publicId);
        if ($session !== null) {
            return $session;
        }

        throw (new ModelNotFoundException)->setModel(WidgetSession::class, [$publicId]);
    }
}
