<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Http\Requests\Api\UpdateConversationRequest;
use App\Http\Resources\Chat\ConversationResource;
use App\Models\Conversation;
use App\Services\Chat\ConversationOrganizerService;
use App\Support\Chat\ConversationArchiveScope;
use App\Support\Chat\ConversationImportance;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConversationController extends Controller
{
    /**
     * List current user's conversations IN THE ACTIVE TEAM, most recent
     * first.
     *
     * R30 — `conversations` is tenant-aware. Scoping only by `user_id`
     * (the relation default) leaked the same list across every team the
     * user belongs to, so the chat sidebar showed identical threads no
     * matter which team was selected in the topbar. `forTenant()`
     * constrains the list to the team the X-Tenant-Id header resolved.
     * Implicit-binding routes (update/destroy/messages) are already
     * tenant-scoped via Conversation::resolveRouteBinding().
     */
    public function index(
        Request $request,
        TenantContext $tenant,
        ConversationOrganizerService $organizer,
    ): JsonResponse {
        $conversations = $organizer->list(
            (int) $request->user()->id,
            $tenant->current(),
            ConversationArchiveScope::fromRequest($request->query('archived')),
        );

        // Bare array, NOT `{data: …}` — see ConversationResource (R27).
        return response()->json(ConversationResource::collection($conversations));
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

        return response()->json(new ConversationResource($conversation), 201);
    }

    /**
     * Rename a conversation.
     */
    public function update(
        UpdateConversationRequest $request,
        Conversation $conversation,
        ConversationOrganizerService $organizer,
    ): JsonResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        // Rename FIRST and on its own: it is activity on the thread, so it
        // keeps bumping `updated_at`, while every organisation write below
        // deliberately does not (see ConversationOrganizerService).
        if ($request->has('title')) {
            $conversation->update(['title' => $request->string('title')->toString()]);
        }

        if ($request->has('chat_folder_id')) {
            $folderId = $request->input('chat_folder_id');
            $organizer->setFolder($conversation, $folderId === null ? null : (int) $folderId);
        }

        if ($request->has('pinned')) {
            $organizer->setPinned($conversation, $request->boolean('pinned'));
        }

        if ($request->has('archived')) {
            $organizer->setArchived($conversation, $request->boolean('archived'));
        }

        if ($request->has('importance')) {
            $organizer->setImportance(
                $conversation,
                ConversationImportance::from($request->string('importance')->toString()),
            );
        }

        return response()->json(new ConversationResource($conversation->refresh()));
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
            ->orderBy('id')
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
