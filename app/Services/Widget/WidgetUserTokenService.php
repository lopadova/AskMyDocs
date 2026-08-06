<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Models\WidgetIdentity;
use App\Models\WidgetKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

final class WidgetUserTokenService
{
    public const PREFIX = 'wu_';

    /** @return array{token:string,expires_at:string,identity:WidgetIdentity} */
    public function issue(WidgetKey $key, string $subject, string $origin): array
    {
        // Include the complete ownership boundary so the same host subject is
        // not correlatable across widgets/projects from a database export.
        $subjectHash = hash_hmac(
            'sha256',
            $key->tenant_id."\0".$key->id."\0".$key->project_key."\0".$subject,
            (string) config('app.key'),
        );
        $identity = WidgetIdentity::query()->forTenant($key->tenant_id)->firstOrCreate(
            ['widget_key_id' => $key->id, 'subject_hash' => $subjectHash],
            [
                'tenant_id' => $key->tenant_id,
                'project_key' => $key->project_key,
            ],
        );
        $identity->forceFill(['last_seen_at' => now()])->saveQuietly();

        $expiresAt = now()->addMinutes((int) config('widget.user_token_ttl_minutes', 15));
        $claims = [
            'v' => 1,
            'jti' => (string) Str::uuid(),
            'wid' => $key->id,
            'pid' => $key->project_key,
            'iid' => $identity->id,
            'iep' => (int) $key->identity_access_epoch,
            'org' => $this->normalizeOrigin($origin),
            'exp' => $expiresAt->timestamp,
        ];

        return [
            'token' => self::PREFIX.Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt->toIso8601String(),
            'identity' => $identity,
        ];
    }

    /** @return array{key:WidgetKey,identity:WidgetIdentity,origin:string}|null */
    public function validate(string $token, ?string $origin): ?array
    {
        if (! str_starts_with($token, self::PREFIX) || ! is_string($origin) || $origin === '') {
            return null;
        }

        try {
            $claims = json_decode(
                Crypt::decryptString(substr($token, strlen(self::PREFIX))),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($claims)
            || ($claims['v'] ?? null) !== 1
            || ! is_int($claims['exp'] ?? null)
            || ! is_int($claims['iep'] ?? null)
            || $claims['exp'] <= now()->timestamp
            || ! hash_equals((string) ($claims['org'] ?? ''), $this->normalizeOrigin($origin))) {
            return null;
        }

        $key = WidgetKey::query()->find($claims['wid'] ?? 0);
        $identity = $key === null
            ? null
            : WidgetIdentity::query()
                ->forTenant($key->tenant_id)
                ->find($claims['iid'] ?? 0);
        if ($key === null || $identity === null
            || ! $key->is_active || ! $key->user_auth_enabled
            || $claims['iep'] !== (int) $key->identity_access_epoch
            || ! $key->originAllowed($origin)
            || $identity->widget_key_id !== $key->id
            || $identity->project_key !== $key->project_key
            || ($claims['pid'] ?? null) !== $key->project_key) {
            return null;
        }

        return ['key' => $key, 'identity' => $identity, 'origin' => $claims['org']];
    }

    private function normalizeOrigin(string $origin): string
    {
        return rtrim(strtolower(trim($origin)), '/');
    }
}
