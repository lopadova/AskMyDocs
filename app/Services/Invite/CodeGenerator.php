<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\InviteCode;
use App\Services\Invite\Support\CodeGenerationException;
use App\Services\Invite\Support\CrockfordBase32;
use Illuminate\Database\QueryException;

/**
 * Code minting (docs/05-code-generation.md). Three kinds:
 *
 *   - random  — CSPRNG-drawn Crockford Base32, generate-then-check with a
 *               UNIQUE(code) backstop and bounded retries.
 *   - vanity  — human-chosen, run through a charset/length/reserved/profanity
 *               gauntlet, then the same UNIQUE backstop.
 *   - signed  — stateless, self-verifying HMAC code (forward seam; the
 *               redemption path treats it like any other persisted code).
 *
 * Every code is persisted in its normalized form via CodeNormalizer so the
 * generator and the redeemer agree on identity. CSPRNG only — random_int /
 * random_bytes, never mt_rand.
 */
final class CodeGenerator
{
    public function __construct(private readonly CodeNormalizer $normalizer)
    {
    }

    /**
     * Mint one random code. $attrs may carry campaign_id / issuer_id /
     * max_uses / expires_at / metadata / tenant_id (tenant auto-fills).
     *
     * @param  array<string, mixed>  $attrs
     */
    public function generateRandom(array $attrs = [], ?int $length = null): InviteCode
    {
        $length ??= (int) config('invite.codes.default_length', 8);
        $this->assertLength($length);

        $alphabet = $this->alphabet();
        $maxAttempts = max(1, (int) config('invite.codes.max_attempts', 5));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $code = $this->normalizer->normalize($this->draw($length, $alphabet));

            try {
                return $this->persist($code, InviteCode::KIND_RANDOM, $attrs);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                // Collision — retry with a fresh draw.
            }
        }

        throw CodeGenerationException::of(
            'collision_exhausted',
            "Exhausted {$maxAttempts} attempts minting a length-{$length} code; increase the length."
        );
    }

    /**
     * Mint $count distinct random codes. De-dupes within the batch in memory;
     * the UNIQUE(code) index rejects cross-batch races (handled by the retry
     * inside generateRandom).
     *
     * @param  array<string, mixed>  $attrs
     * @return array<int, InviteCode>
     */
    public function generateBatch(int $count, array $attrs = [], ?int $length = null): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRandom($attrs, $length);
        }

        return $codes;
    }

    /**
     * Mint a human-chosen vanity code.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function mintVanity(string $requested, array $attrs = []): InviteCode
    {
        $code = $this->normalizer->normalize($requested);

        if ($code === '' || ! $this->matchesAlphabet($code)) {
            throw CodeGenerationException::of('vanity_malformed', 'Vanity code has illegal characters.');
        }

        if ($this->isReserved($code)) {
            throw CodeGenerationException::of('vanity_reserved', 'Vanity code is reserved.');
        }

        if ($this->isProfane($code)) {
            // Never echo the offending term back.
            throw CodeGenerationException::of('vanity_profane', 'Vanity code rejected by content policy.');
        }

        try {
            return $this->persist($code, InviteCode::KIND_VANITY, $attrs);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw CodeGenerationException::of('vanity_taken', 'Vanity code is already taken.');
            }
            throw $e;
        }
    }

    /**
     * Mint a stateless signed code. The payload MUST carry campaign / capacity
     * / exp and NO PII. The signature is an HMAC over the url-safe body; the
     * persisted `code` is `body.signature`.
     *
     * @param  array{campaign: string, capacity: int, exp: int}  $payload
     * @param  array<string, mixed>  $attrs
     */
    public function mintSigned(array $payload, array $attrs = []): InviteCode
    {
        if (! isset($payload['campaign'], $payload['capacity'], $payload['exp'])) {
            throw CodeGenerationException::of('sign_payload_invalid', 'Signed payload requires campaign, capacity, exp.');
        }

        $secret = $this->signingSecret();
        // Crockford-encode body + signature so the persisted code survives
        // CodeNormalizer unchanged (uppercase, no I/L/O/U, dot separator kept).
        $body = CrockfordBase32::encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = CrockfordBase32::encode(hash_hmac('sha256', $body, $secret, true));
        $code = $this->normalizer->normalize($body . '.' . $signature);

        return $this->persist($code, InviteCode::KIND_SIGNED, array_merge($attrs, [
            'payload' => $payload,
            'signature' => $signature,
        ]));
    }

    /**
     * Verify a signed code without touching the DB (pure, constant-time).
     *
     * @return array{ok: bool, payload?: array<string, mixed>, reason?: string}
     */
    public function verifySigned(string $code): array
    {
        $normalized = $this->normalizer->normalize($code);
        $dot = strrpos($normalized, '.');
        if ($dot === false) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        $body = substr($normalized, 0, $dot);
        $sig = substr($normalized, $dot + 1);
        $expected = CrockfordBase32::encode(hash_hmac('sha256', $body, $this->signingSecret(), true));

        if (! hash_equals($expected, $sig)) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        $payload = json_decode(CrockfordBase32::decode($body), true);
        if (! is_array($payload) || ! isset($payload['exp'])) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        if (now()->getTimestamp() >= (int) $payload['exp']) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function persist(string $code, string $kind, array $attrs): InviteCode
    {
        return InviteCode::create(array_merge([
            'state' => InviteCode::STATE_ACTIVE,
            'max_uses' => 1,
            'current_uses' => 0,
        ], $attrs, [
            'code' => $code,
            'code_kind' => $kind,
        ]));
    }

    private function draw(int $length, string $alphabet): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    private function alphabet(): string
    {
        $alphabet = (string) config('invite.codes.alphabet', '0123456789ABCDEFGHJKMNPQRSTVWXYZ');

        // The alphabet must not contain the Crockford confusables (I L O U) nor
        // any duplicate — otherwise normalization folds two glyphs into one and
        // the keyspace silently shrinks.
        if (preg_match('/[ILOU]/', $alphabet) === 1 || count(array_unique(str_split($alphabet))) !== strlen($alphabet)) {
            throw CodeGenerationException::of('invalid_alphabet', 'Alphabet contains duplicates or ambiguous I/L/O/U.');
        }

        return $alphabet;
    }

    private function assertLength(int $length): void
    {
        if ($length < 4) {
            throw CodeGenerationException::of('length_too_short', 'Random code length must be at least 4.');
        }
    }

    private function matchesAlphabet(string $code): bool
    {
        $alphabet = preg_quote($this->alphabet(), '/');

        return preg_match('/^[' . $alphabet . ']+$/', $code) === 1;
    }

    private function isReserved(string $code): bool
    {
        // Normalize each reserved entry so a human-form word containing a
        // confusable (ADMIN → ADM1N, API → AP1) still matches the normalized
        // vanity request rather than silently never matching.
        foreach ((array) config('invite.codes.reserved', []) as $reserved) {
            if ($this->normalizer->normalize((string) $reserved) === $code) {
                return true;
            }
        }

        return false;
    }

    private function isProfane(string $code): bool
    {
        // Minimal leetspeak-folded substring check. The host can extend the
        // list via config; this is a forward seam, not a comprehensive filter.
        $folded = strtr($code, ['4' => 'A', '3' => 'E', '1' => 'I', '0' => 'O', '5' => 'S', '7' => 'T']);
        foreach ((array) config('invite.codes.profanity', ['ASS', 'FUCK', 'SHIT']) as $term) {
            if (str_contains($folded, strtoupper((string) $term))) {
                return true;
            }
        }

        return false;
    }

    private function signingSecret(): string
    {
        $secret = config('invite.signing_key');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        // Derive from APP_KEY so dev never emits an unsigned code. Production
        // MUST set INVITE_SIGNING_KEY (Phase 6 enforces + rotates).
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw CodeGenerationException::of('sign_secret_unavailable', 'No signing key and APP_KEY is empty.');
        }

        return hash_hmac('sha256', 'invite-signing', $appKey);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Unique violation');
    }
}
