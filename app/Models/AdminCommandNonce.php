<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use confirmation nonce for destructive admin commands.
 *
 * Created by `POST /api/admin/commands/preview` when the requested
 * command declares `destructive: true` in `config/admin.php`.
 * Consumed (`used_at` stamped) by the matching `POST /api/admin/commands/run`.
 *
 * The table-level unique index is on `token_hash` (not the token
 * itself). We never persist the plaintext — the plaintext is returned
 * to the client exactly once, in the preview response body, and any
 * future /run call hashes it again to look up this row.
 *
 * args_hash is the sha256 of the canonical-JSON-encoded args at preview
 * time; on /run we recompute and compare, rejecting "preview asked for
 * days=30, run tried days=90" drift.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $command
 * @property int $user_id
 * @property string $args_hash
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 */
class AdminCommandNonce extends Model
{
    use BelongsToTenant;

    protected $table = 'admin_command_nonces';

    public $timestamps = false;

    /**
     * Every non-id column is mass-assignable via $guarded = ['id']. The
     * nonce is not user-facing and the service layer is the only writer.
     *
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $this->expires_at->lt($now);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
