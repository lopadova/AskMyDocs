<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

/**
 * Salted-HMAC hashing + email canonicalization for the invite subsystem
 * (docs/15-security-privacy.md, docs/10-anti-abuse.md).
 *
 * PII subjects (ip / email / fingerprint) are only ever MATCHED, never
 * displayed, so they become a salted hash before they touch storage. Email is
 * canonicalized first (lowercase, trim, strip +tag, fold Gmail dots) so two
 * aliases of the same inbox hash equal — the basis of the alias self-referral
 * check.
 */
final class PiiHasher
{
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->salt());
    }

    /**
     * Canonicalize an email for matching: lowercase, trim, strip the +tag, and
     * fold dots in the local part for gmail/googlemail only.
     */
    public function canonicalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        // Strip +tag.
        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }

        // Gmail folds dots in the local part; other providers do not.
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
        }

        return $local . '@' . $domain;
    }

    public function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : substr($email, $at + 1);
    }

    private function salt(): string
    {
        return (string) (config('invite.pii.hash_salt') ?? config('app.key'));
    }
}
