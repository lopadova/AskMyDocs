<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KbRetrievalProfile;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Chat readiness only: never exposes the administrator's profile content. */
final class KbRetrievalProfileStatusController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'project_keys' => ['required', 'array', 'min:1', 'max:100'],
            'project_keys.*' => ['required', 'string', 'max:120', 'distinct', 'not_in:*'],
        ]);
        $projects = $data['project_keys'];
        $allowed = $request->user()->allowedProjects();
        abort_unless(in_array(User::PROJECT_WILDCARD, $allowed, true) || array_diff($projects, $allowed) === [], 403);
        $configured = KbRetrievalProfile::query()->forTenant($tenant->current())
            ->whereIn('project_key', $projects)->pluck('project_key')->all();

        return response()->json([
            'required' => (bool) config('kb.investigation.enabled', true),
            'profiles' => array_map(static fn (string $key): array => [
                'project_key' => $key,
                'configured' => in_array($key, $configured, true),
            ], $projects),
        ]);
    }
}
