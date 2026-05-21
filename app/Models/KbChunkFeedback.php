<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KbChunkFeedback extends Model
{
    use BelongsToTenant;

    public const SIGNAL_SHOULD_HAVE_CITED = 'should_have_cited';
    public const SIGNAL_NOT_RELEVANT = 'not_relevant';

    protected $table = 'kb_chunk_feedback';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'knowledge_chunk_id',
        'signal',
    ];

    /**
     * @return array<int, string>
     */
    public static function signals(): array
    {
        return [
            self::SIGNAL_SHOULD_HAVE_CITED,
            self::SIGNAL_NOT_RELEVANT,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }
}

