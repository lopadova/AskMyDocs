<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Agent\AgentExecutionContextFactory;
use App\Agent\Tools\AgentLiveSourceSelection;
use App\Agent\Tools\AgentToolDefinition;
use App\Agent\Tools\AgentToolRegistry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Returns the live API/MCP sources the current actor may use in chat. */
final class AgentLiveSourceController extends Controller
{
    public function __invoke(
        Request $request,
        AgentExecutionContextFactory $contexts,
        AgentToolRegistry $registry,
        AgentLiveSourceSelection $selection,
    ): JsonResponse {
        $validated = $request->validate([
            'project_key' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        $projectKey = is_string($validated['project_key'] ?? null)
            ? trim($validated['project_key'])
            : null;
        $projectKey = $projectKey === '' ? null : $projectKey;
        if ($projectKey !== null) {
            $allowed = $user->allowedProjects();
            abort_unless(
                in_array(User::PROJECT_WILDCARD, $allowed, true)
                    || in_array($projectKey, $allowed, true),
                403,
            );
        }

        $context = $contexts->forUser($user, $projectKey);
        $tools = array_filter(
            $registry->forContext($context, $user),
            static fn (AgentToolDefinition $tool): bool => $tool->readOnly
                && ! (bool) ($tool->metadata['confirmation_required'] ?? false),
        );

        return response()->json(['data' => $selection->catalog($tools)]);
    }
}
