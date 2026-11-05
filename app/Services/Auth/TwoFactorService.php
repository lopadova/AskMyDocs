<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class TwoFactorService
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly Encrypter $encrypter) {}

    /** @return array{secret: string, provisioning_uri: string} */
    public function begin(User $user): array
    {
        $secret = $this->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $this->encrypter->encryptString($secret),
            'two_factor_enabled_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        return [
            'secret' => $secret,
            'provisioning_uri' => $this->provisioningUri($user, $secret),
        ];
    }

    /** @return list<string> */
    public function confirm(User $user, string $code): array
    {
        $secret = $this->secret($user);
        abort_if($secret === null || ! $this->validTotp($user, $secret, $code, consume: true), 422, 'The provided two-factor code is invalid.');

        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $plain = strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
            $codes[] = $plain;
            $hashes[] = password_hash($this->canonical($plain), PASSWORD_DEFAULT);
        }

        $user->forceFill([
            'two_factor_enabled_at' => Carbon::now(),
            'two_factor_recovery_codes' => json_encode($hashes, JSON_THROW_ON_ERROR),
        ])->save();

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        abort_if(! $this->verify($user, $code), 422, 'The provided two-factor code is invalid.');

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_enabled_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }

    public function verify(User $user, string $code): bool
    {
        $secret = $this->secret($user);
        if ($secret === null || $user->two_factor_enabled_at === null) {
            return false;
        }

        if ($this->validTotp($user, $secret, $code, consume: true)) {
            return true;
        }

        $hashes = json_decode((string) $user->two_factor_recovery_codes, true);
        if (! is_array($hashes)) {
            return false;
        }

        $matched = null;
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify($this->canonical($code), $hash)) {
                $matched = $index;
                break;
            }
        }

        if ($matched === null) {
            return false;
        }

        array_splice($hashes, (int) $matched, 1);
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR)])->save();

        return true;
    }

    private function validTotp(User $user, string $secret, string $code, bool $consume): bool
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $step = intdiv(time(), 30);
        for ($candidate = $step - 1; $candidate <= $step + 1; $candidate++) {
            if ($this->hotp($secret, $candidate) !== $code) {
                continue;
            }

            if ($consume && $user->two_factor_last_used_step !== null && $candidate <= (int) $user->two_factor_last_used_step) {
                return false;
            }

            if ($consume) {
                $user->forceFill(['two_factor_last_used_step' => $candidate])->save();
            }

            return true;
        }

        return false;
    }

    private function secret(User $user): ?string
    {
        $raw = $user->two_factor_secret;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $secret = $this->encrypter->decryptString($raw);
        } catch (\Throwable) {
            return null;
        }

        return $secret !== '' ? $secret : null;
    }

    private function generateSecret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $secret .= self::BASE32[ord($byte) >> 3];
            $secret .= self::BASE32[(ord($byte) & 0x07) << 2];
        }

        return substr($secret, 0, 32);
    }

    private function provisioningUri(User $user, string $secret): string
    {
        $issuer = (string) config('auth.two_factor.issuer', config('app.name', 'AskMyDocs'));
        $label = $issuer.':'.$user->email;

        return 'otpauth://totp/'.rawurlencode($label).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private function hotp(string $secret, int $counter): string
    {
        $binary = '';
        $buffer = '';
        foreach (str_split($secret) as $character) {
            $buffer .= str_pad(decbin(strpos(self::BASE32, $character)), 5, '0', STR_PAD_LEFT);
        }
        foreach (str_split($buffer, 8) as $octet) {
            if (strlen($octet) === 8) {
                $binary .= chr(bindec($octet));
            }
        }

        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $counter), $binary, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private function canonical(string $code): string
    {
        return strtoupper(str_replace(['-', ' ', '_'], '', trim($code)));
    }
}
