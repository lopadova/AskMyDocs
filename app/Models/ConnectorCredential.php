<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v4.5/W1 — Encrypted OAuth credential row for a connector installation.
 *
 * NEVER read or write `encrypted_access_token` / `encrypted_refresh_token`
 * directly — funnel every access through
 * {@see \App\Connectors\Auth\OAuthCredentialVault}, which handles the
 * `Crypt::encryptString()` / `Crypt::decryptString()` round-trip and the
 * "refresh on stale token" semantics.
 *
 * The encrypted columns hold whatever bytes `Crypt::encryptString()`
 * returned — opaque base64 from the AES envelope. The plain access /
 * refresh tokens are never persisted in clear text, and the vault holds
 * the only path to retrieve them.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $connector_installation_id
 * @property string $encrypted_access_token
 * @property string|null $encrypted_refresh_token
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property array<string,mixed>|null $extra_json
 */
class ConnectorCredential extends Model
{
    use BelongsToTenant;

    protected $table = 'connector_credentials';

    protected $fillable = [
        'tenant_id',
        'connector_installation_id',
        'encrypted_access_token',
        'encrypted_refresh_token',
        'expires_at',
        'extra_json',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'extra_json' => 'array',
    ];

    protected $hidden = [
        'encrypted_access_token',
        'encrypted_refresh_token',
    ];

    public function installation(): BelongsTo
    {
        return $this->belongsTo(ConnectorInstallation::class, 'connector_installation_id');
    }

    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        $now ??= now();

        return $this->expires_at->lt($now);
    }
}
