<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    /**
     * List current user's conversations, most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()
            ->conversations()
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'project_key', 'created_at', 'updated_at']);

        return response()->json($conversations);
    }

    /**
     * Create a new empty conversation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['nullable', 'string', 'max:120'],
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => null,
            'project_key' => $validated['project_key'] ?? null,
        ]);

        return response()->json($conversation, 201);
    }

    /**
     * Rename a conversation.
     */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $conversation->update(['title' => $validated['title']]);

        return response()->json($conversation);
    }

    /**
     * Delete a conversation and all its messages.
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $conversation->delete();

        return response()->json(null, 204);
    }

    /**
     * Generate a title for the conversation using AI.
     */
    public function generateTitle(Request $request, Conversation $conversation, AiManager $ai): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $firstMessage = $conversation->messages()
            ->where('role', 'user')
            ->first();

        if (! $firstMessage) {
            return response()->json(['title' => 'Nuova chat']);
        }

        $aiResponse = $ai->chat(
            'Genera un titolo breve (massimo 50 caratteri) per una conversazione che inizia con la seguente domanda. Rispondi SOLO con il titolo, niente altro. Non usare virgolette.',
            mb_substr($firstMessage->content, 0, 500),
            ['max_tokens' => 60],
        );

        $title = trim($aiResponse->content, " \n\r\t\v\0\"'");
        $title = mb_substr($title, 0, 100);

        $conversation->update(['title' => $title]);

        return response()->json(['title' => $title]);
    }
}
