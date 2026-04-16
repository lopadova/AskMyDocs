<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function index(Request $request, ?Conversation $conversation = null): View
    {
        // Ensure the user owns the conversation
        if ($conversation && $conversation->user_id !== $request->user()->id) {
            abort(403);
        }

        return view('chat', [
            'activeConversation' => $conversation,
        ]);
    }
}
