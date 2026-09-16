<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Chat\ConversationImportance;
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
        'chat_folder_id',
        'pinned_at',
        'archived_at',
        'importance',
    ];

    protected $casts = [
        'pinned_at' => 'datetime',
        'archived_at' => 'datetime',
        'importance' => ConversationImportance::class,
    ];

    /**
     * Mirror the column default on the MODEL, not just in the schema.
     *
     * `importance` has a DB default, so a row is always valid — but a
     * freshly `create()`d instance that never sent the column has the
     * attribute ABSENT, and the enum cast then yields null. Anything
     * reading `$conversation->importance->value` on that instance blows
     * up with "property value on null", which is exactly how POST
     * /conversations started answering 500 once it began returning the
     * full resource. Defaulting here keeps a new instance coherent
     * without a re-read.
     */
    protected $attributes = [
        'importance' => 'normal',
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

    /**
     * The folder this session is filed under, if any. Null = "unfiled",
     * which is the default and also what a folder delete leaves behind
     * (nullOnDelete — never a cascade).
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(ChatFolder::class, 'chat_folder_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest('id')->limit(1);
    }
}
