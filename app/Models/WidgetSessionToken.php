<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WidgetSessionToken — token di sessione opzionale origin-bound per
 * la modalità browser (A). Elimina la necessità di passare la public_key
 * a ogni richiesta dal browser.
 *
 * Flusso M5.2:
 *   1. POST /api/widget/session-token → conia un token a breve scadenza
 *   2. Il FE usa il token in `Authorization: Bearer <token>` al posto di
 *      X-Widget-Key nelle richieste successive
 *   3. Il consumo è ATOMICO (R21): lockForUpdate + write nella stessa transazione
 *      (la colonna `token` è UNIQUE; `consumed_at` è un nullable timestamp — il
 *      lock è la guardia di single-use, non un UNIQUE su consumed_at).
 *
 * #14: a riposo si persiste l'hash sha256 del bearer, mai il plaintext.
 * R31: BelongsToTenant + tenant_id in $fillable.
 */
class WidgetSessionToken extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'token',
        'widget_key_id',
        'widget_session_id',
        'origin',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /** @return BelongsTo<WidgetKey, WidgetSessionToken> */
    public function widgetKey(): BelongsTo
    {
        return $this->belongsTo(WidgetKey::class);
    }

    /** @return BelongsTo<WidgetSession, WidgetSessionToken> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WidgetSession::class, 'widget_session_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}