<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\SuggestedFollowupGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v4.5/W7 — adjunct endpoints for the Vercel AI SDK UI Tier 1 + Tier 2
 * surface that do NOT belong in the existing controllers:
 *
 *   1. {@see costRates()} — `GET /api/chat/cost-rates`
 *       Public list of (provider, model) → input/output USD per million
 *       tokens. The FE token/cost meter on every assistant bubble
 *       multiplies persisted token counts by this rate. Anonymous
 *       endpoint (no PII, no per-tenant data) — cached at the CDN /
 *       browser side via a short max-age.
 *
 *   2. {@see branchFromMessage()} — `POST /conversations/{conversation}/branch-from-message/{message}`
 *       Forks the current conversation into a new one rooted at the
 *       given message id. Every message up to AND INCLUDING the named
 *       one is copied into the new conversation (same role + content
 *       + metadata + tenant). The user can then "regenerate" or send a
 *       follow-up off the branch without polluting the source thread.
 *
 *   3. {@see suggestedFollowups()} — `POST /conversations/{conversation}/suggested-followups`
 *       Returns 3 short follow-up prompts the user is likely to want
 *       after the most recent assistant turn. Generates via a cheap
 *       LLM call (re-uses the conversation's chat provider). Falls
 *       back to a small static set if the provider call fails so the
 *       FE never has to handle a 5xx for a non-essential surface.
 *
 * R30/R31: every query is tenant-scoped through the `user()` /
 * conversation ownership check (mirrors ConversationController).
 *
 * R36 / R26: BE tests use `Http::fake()` for the LLM call.
 */
class ChatExtrasController extends Controller
{
    public function __construct(
        private readonly SuggestedFollowupGenerator $followupGenerator,
    ) {
    }

    /**
     * Public cost-rate lookup. The FE caches this for the session;
     * we set a 1-hour CDN cache so price updates flow within an hour
     * without server-side push.
     *
     * Wire shape: `{ rates: { <provider>: { <model>: { input, output } } } }`
     * — keep the response keyed by provider to match the FE shape
     * (provider+model is the natural lookup key).
     */
    public function costRates(): JsonResponse
    {
        $rates = config('ai.cost_rates', []);
        if (! is_array($rates)) {
            $rates = [];
        }

        return response()
            ->json(['rates' => $rates])
            ->header('Cache-Control', 'public, max-age=3600, must-revalidate');
    }

    /**
     * Branch (fork) a conversation at a given message id. Every message
     * up to AND INCLUDING the named one is copied to a new conversation;
     * the user can regenerate / extend the branch without affecting the
     * source thread.
     *
     * Returns the newly-created conversation row + the copied message
     * ids so the FE can prefetch the messages list with no extra
     * round-trip. Tenant-scoped — only the owner can fork.
     *
     * Why include the named message? Branching "from this reply"
     * intuitively means the new thread starts WITH that reply already
     * visible; the user then sends a NEW question that builds on it.
     * Excluding the named message would create an awkward UX where the
     * branch starts before the reply the user chose as the anchor.
     */
    public function branchFromMessage(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $user = $request->user();
        if (! $user || $conversation->user_id !== $user->id) {
            abort(403);
        }

        if ($message->conversation_id !== $conversation->id) {
            abort(404, 'Message does not belong to this conversation.');
        }

        // Copy every message up to AND INCLUDING the chosen anchor.
        // Order primarily by created_at, fall back to id for stable
        // ordering when timestamps share the same precision (SQLite
        // tests can produce ties). The id <= filter is the safety net
        // — created_at ties would otherwise pull post-anchor rows
        // into the branch.
        $messages = $conversation->messages()
            ->where(function ($q) use ($message) {
                $q->where('created_at', '<', $message->created_at)
                    ->orWhere(function ($q2) use ($message) {
                        $q2->where('created_at', '=', $message->created_at)
                            ->where('id', '<=', $message->id);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // We do NOT wrap in DB::transaction here: every persistence in
        // this method targets a NEW row (no concurrent-read invariant
        // to protect), and an explicit nested transaction conflicts
        // with the test suite's RefreshDatabase outer transaction on
        // some SQLite versions. If a copy mid-way fails, the user
        // sees a partial branch — they delete it and retry. The cost
        // of guaranteeing atomicity here outweighs the recovery path.
        $branch = $request->user()->conversations()->create([
            'title' => $conversation->title ? $conversation->title.' (branch)' : 'Branch',
            'project_key' => $conversation->project_key,
        ]);

        $copied = [];
        foreach ($messages as $source) {
            $copy = $branch->messages()->create([
                'role' => $source->role,
                'content' => $source->content,
                'metadata' => $source->metadata,
                'confidence' => $source->confidence,
                'refusal_reason' => $source->refusal_reason,
            ]);
            $copied[] = $copy->id;
        }

        return response()->json([
            'conversation' => $branch->refresh(),
            'copied_message_ids' => $copied,
        ], 201);
    }

    /**
     * Generate 3 suggested follow-up prompts based on the most recent
     * assistant turn. Failure-tolerant — if the provider errors we
     * return an empty list (HTTP 200, `{suggestions: []}`) so the FE
     * pill bar simply doesn't render. Mirror of the "graceful degrade"
     * pattern in ChatLogManager::log() — never break the UI for a
     * non-essential surface.
     */
    public function suggestedFollowups(
        Request $request,
        Conversation $conversation,
        AiManager $ai,
    ): JsonResponse {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        $suggestions = $this->followupGenerator->generate($conversation, $ai);

        return response()->json(['suggestions' => $suggestions]);
    }
}
