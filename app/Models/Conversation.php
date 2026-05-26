<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'project_key',
    ];

    /**
     * R30 (Audit#3 HIGH-4) — scope implicit route-model binding to the
     * active tenant. The chat controllers (Conversation/Message/MessageStream)
     * check $conversation->user_id but Laravel resolves {conversation} by
     * GLOBAL id; without this a user spanning tenants (or an id-enumerating
     * attacker) could reach another tenant's conversation. A miss returns
     * null → ModelNotFoundException → 404.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?? $this->getRouteKeyName();

        return $this->newQuery()
            ->forTenant(app(TenantContext::class)->current())
            ->where($field, $value)
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest('created_at')->limit(1);
    }
}
