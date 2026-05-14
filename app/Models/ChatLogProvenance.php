<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ChatLogProvenance extends Model
{
    use BelongsToTenant;

    protected $table = 'chat_log_provenance';

    protected $guarded = ['id'];

    protected $casts = [
        'chat_log_id' => 'int',
        'message_id' => 'int',
        'answer_token_start' => 'int',
        'answer_token_end' => 'int',
        'knowledge_chunk_id' => 'int',
        'contribution_score' => 'float',
    ];
}
