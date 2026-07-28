<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-scoped, single-use authorization for a destructive demo e-mail
 * dataset operation. Only the SHA-256 token hash is persisted.
 */
final class EmailDatasetOperationNonce extends Model
{
    use BelongsToTenant;

    protected $table = 'email_dataset_operation_nonces';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected $casts = [
        'selection_json' => 'array',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
