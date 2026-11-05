<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conversation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChatApiConversationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $conversation = $request->route('conversation');
        if ($conversation instanceof Conversation) {
            abort_unless((int) $conversation->user_id === (int) $request->user()->id, 404);
            $project = $conversation->project_key;
        } else {
            $run = $request->route('run');
            $project = is_string($run)
                ? app(\App\Agent\AgentRunAccess::class)->forUserOrFail($run, $request->user())->project_key
                : $request->input('project_key');
        }
        if (is_string($project) && trim($project) !== '') {
            abort_unless($request->user()->canReadAllProjects()
                || in_array(trim($project), $request->user()->allowedProjects(), true), 403);
        }

        return $next($request);
    }
}
