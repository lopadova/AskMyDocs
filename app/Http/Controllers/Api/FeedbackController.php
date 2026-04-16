<?php

namespace App\Http\Controllers\Api;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class FeedbackController extends Controller
{
    /**
     * Rate an assistant message (positive/negative).
     *
     * Toggle behavior: sending the same rating again removes it.
     */
    public function store(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($message->conversation_id !== $conversation->id || $message->role !== 'assistant') {
            abort(422, 'Only assistant messages can be rated.');
        }

        $validated = $request->validate([
            'rating' => ['required', 'in:positive,negative'],
        ]);

        // Toggle: same rating again = remove
        $newRating = $message->rating === $validated['rating'] ? null : $validated['rating'];
        $message->update(['rating' => $newRating]);

        return response()->json([
            'rating' => $newRating,
        ]);
    }
}
