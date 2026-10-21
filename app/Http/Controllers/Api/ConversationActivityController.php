<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Conversation;
use App\Services\Chat\ConversationActivityService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ConversationActivityController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ConversationActivityService $activity): JsonResponse
    {
        return response()->json($activity->list((int) $request->user()->id, $tenant->current()))
            ->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, Conversation $conversation, ConversationActivityService $activity): JsonResponse
    {
        abort_unless((int) $conversation->user_id === (int) $request->user()->id, 403);
        $validated = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);

        return response()->json([
            'last_read_message_id' => $activity->markRead($conversation, (int) $validated['message_id']),
        ]);
    }
}
