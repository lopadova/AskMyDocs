<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable forensic trail of every admin command invocation.
 *
 * Lifecycle:
 *   1. `CommandRunnerService::run()` opens a row with `status='started'`
 *      BEFORE invoking `Artisan::call()`. If the insert fails, the
 *      command is NEVER executed.
 *   2. On success: status → `completed`, `exit_code`, `stdout_head`,
 *      `completed_at` are filled.
 *   3. On exception: status → `failed`, `error_message` + partial stdout
 *      captured.
 *   4. `preview()` rejects (bad token, mismatched args_hash, missing
 *      permission) write status=`rejected` so abuse attempts are still
 *      recorded.
 *
 * Rows are NEVER updated after the lifecycle ends. The model deliberately
 * has no SoftDeletes trait and no timestamps auto-management (the
 * started_at / completed_at pair is the canonical event clock).
 *
 * NOT tenant-scoped — this is a global ops-level record, not a KB
 * projection. `kb:delete` invocations do write a row here AND a
 * `kb_canonical_audit` row per R10.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $command
 * @property array $args_json
 * @property string $status
 * @property int|null $exit_code
 * @property string|null $stdout_head
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property string|null $client_ip
 * @property string|null $user_agent
 */
class AdminCommandAudit extends Model
{
    use BelongsToTenant;

    protected $table = 'admin_command_audit';

    public $timestamps = false;

    /**
     * `$guarded = ['id']` mirrors the model contract of `KbCanonicalAudit`:
     * every column is mass-assignable EXCEPT the primary key. This keeps
     * the lifecycle helper shapes (`CommandRunnerService::beginAudit()`
     * etc.) concise without losing the PK-immutability guarantee.
     *
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    protected $casts = [
        'args_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'exit_code' => 'integer',
    ];

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
