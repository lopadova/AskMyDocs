<?php

namespace App\Http\Controllers\Api;

use App\Ai\AiManager;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\SuggestedFollowupGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * v4.5/W7 — adjunct endpoints for the Vercel AI SDK UI Tier 1 + Tier 2
 * surface that do NOT belong in the existing controllers:
 *
 *   1. {@see costRates()} — `GET /api/chat/cost-rates`
 *       Session-authenticated (auth middleware) list of
 *       (provider, model) → input/output USD per million tokens.
 *       The FE token/cost meter on every assistant bubble multiplies
 *       persisted token counts by this rate. Contains no PII or
 *       per-tenant data; response carries a short CDN/browser
 *       max-age so price updates propagate within an hour.
 *
 *   2. {@see branchFromMessage()} — `POST /conversations/{conversation}/branch-from-message/{message}`
 *       Forks the current conversation into a new one rooted at the
 *       given message id. Every message up to AND INCLUDING the named
 *       one is copied into the new conversation (same role + content
 *       + metadata + tenant), inside a DB transaction so the branch
 *       creation and message copies are atomic.
 *
 *   3. {@see suggestedFollowups()} — `POST /conversations/{conversation}/suggested-followups`
 *       Returns 3 short follow-up prompts the user is likely to want
 *       after the most recent assistant turn. Generates via a cheap
 *       LLM call (re-uses the conversation's chat provider). Returns
 *       `{suggestions: []}` on any provider failure so the FE never
 *       sees a 5xx for a non-essential surface.
 *
 *   4. {@see truncateMessagesFrom()} — `DELETE /conversations/{conversation}/messages-from/{message}`
 *       Deletes the given message AND all subsequent messages from the
 *       conversation. Used by the inline user-message edit flow: the
 *       FE calls this before `sendMessage()` so the BE history window
 *       re-runs from the edit point (R20 — BE context is DB-authoritative).
 *
 * R30/R31: every query is tenant-scoped through the `user()` /
 * conversation ownership check (mirrors ConversationController).
 *
 * R26: BE tests mock `AiManager` via Mockery; no real LLM calls in CI.
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

        // Wrap branch creation + message copies in a single transaction
        // so a mid-loop insert failure cannot leave an empty branch
        // conversation. Laravel's RefreshDatabase wraps tests in a
        // transaction and inner DB::transaction() calls nest via
        // savepoints (supported by both PostgreSQL and SQLite).
        [$branch, $copied] = DB::transaction(function () use ($request, $conversation, $messages) {
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

            return [$branch, $copied];
        });

        return response()->json([
            'conversation' => $branch->refresh(),
            'copied_message_ids' => $copied,
        ], 201);
    }

    /**
     * Truncate a conversation from a given message onwards (inclusive).
     * Deletes the named message AND every subsequent message so the
     * backend history window re-runs from the edit point when the
     * caller's next `sendMessage()` fires.
     *
     * Used exclusively by the inline user-message edit flow:
     *   1. FE calls this endpoint with the id of the message being edited.
     *   2. FE calls `sendMessage({ text: newContent })`.
     *   3. On `onFinish`, TanStack query invalidation refetches the
     *      trimmed history + new user + assistant pair — the thread
     *      looks exactly like the edit replaced the original message.
     *
     * R30: ownership check mirrors the other mutation endpoints.
     */
    public function truncateMessagesFrom(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($message->conversation_id !== $conversation->id) {
            abort(404, 'Message does not belong to this conversation.');
        }

        $deleted = $conversation->messages()
            ->where('id', '>=', $message->id)
            ->delete();

        return response()->json(['deleted_count' => $deleted]);
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
