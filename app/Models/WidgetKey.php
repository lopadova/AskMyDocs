<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

/**
 * WidgetKey — credenziale del widget KITT embeddabile (vedi migration).
 *
 * R31: usa BelongsToTenant (auto-fill tenant_id) e dichiara tenant_id in
 * $fillable. R30: chi risolve la key imposta TenantContext + project DA QUI,
 * mai dal client.
 */
class WidgetKey extends Model
{
    use BelongsToTenant;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'tenant_id',
        'project_key',
        'public_key',
        'secret_hash',
        'allowed_origins',
        'rate_limit',
        'skill',
        'is_active',
        'label',
        'last_used_at',
    ];

    protected $casts = [
        'allowed_origins' => 'array',
        'rate_limit' => 'integer',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /** Non esporre mai l'hash del secret nelle serializzazioni JSON. */
    protected $hidden = [
        'secret_hash',
    ];

    /** @return HasMany<WidgetSession> */
    public function sessions(): HasMany
    {
        return $this->hasMany(WidgetSession::class);
    }

    /**
     * Verifica che un Origin sia in allowlist con confronto ESATTO (R19):
     * niente regex/substring — `https://evil-example.com` non deve passare
     * per `https://example.com`. Origin normalizzato (lowercase, no slash
     * finale). Allowlist vuota ⇒ nessun Origin ammesso (la modalità A va
     * configurata esplicitamente).
     */
    public function originAllowed(?string $origin): bool
    {
        if (! is_string($origin) || $origin === '') {
            return false;
        }

        $needle = $this->normalizeOrigin($origin);
        $allowed = is_array($this->allowed_origins) ? $this->allowed_origins : [];

        foreach ($allowed as $candidate) {
            if (is_string($candidate) && $this->normalizeOrigin($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** Confronto a tempo costante del secret della modalità proxy (B). */
    public function matchesSecret(string $secret): bool
    {
        if (! is_string($this->secret_hash) || $this->secret_hash === '') {
            return false;
        }

        return Hash::check($secret, $this->secret_hash);
    }

    private function normalizeOrigin(string $origin): string
    {
        return rtrim(strtolower(trim($origin)), '/');
    }
}
