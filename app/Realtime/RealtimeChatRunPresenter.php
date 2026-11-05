<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Models\AgentRun;

final class RealtimeChatRunPresenter
{
    /** The same receipt is available before completion and in the canonical tool result. */
    public function present(AgentRun $run): array
    {
        $message = $run->conversation->messages()->whereKey(data_get($run->input_json, 'user_message_id'))->firstOrFail();

        return [
            'run_id' => $run->run_id, 'status' => $run->status, 'locale' => $run->locale,
            'events_url' => '/agent-runs/'.$run->run_id.'/events',
            'cancel_url' => '/agent-runs/'.$run->run_id.'/cancel',
            'continue_url' => '/agent-runs/'.$run->run_id.'/continue',
            'user_message' => $message->only(['id', 'role', 'content', 'metadata', 'created_at']),
        ];
    }
}
