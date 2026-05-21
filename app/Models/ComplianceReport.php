<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ComplianceReport extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'compliance_reports';

    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'payload_json',
        'hash_sha256',
        'hash_hmac',
        'pdf_path',
        'generated_at',
        'generated_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payload_json' => 'array',
        'generated_at' => 'datetime',
    ];

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

