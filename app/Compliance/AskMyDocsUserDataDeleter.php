<?php

namespace App\Compliance;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ChatLog;
use Padosoft\AiActCompliance\DSAR\Contracts\UserDataDeleter;

class AskMyDocsUserDataDeleter implements UserDataDeleter
{
    public function delete(object $user): void
    {
        $userId = (string) ($user->id ?? '');

        Message::query()->where('user_id', $userId)->delete();
        ChatLog::query()->where('user_id', $userId)->delete();
        Conversation::query()->where('user_id', $userId)->delete();
    }
}
