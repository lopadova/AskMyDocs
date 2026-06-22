<?php

declare(strict_types=1);

/**
 * Invite system configuration.
 *
 * Knobs for the invite-by-code / referral subsystem. Every value is
 * env-overridable; the defaults are the safe production posture described in
 * the handoff docs (Spec4LLM/InviteSystem). Keep this in lockstep with
 * .env.example and the README per R6/R9.
 */
return [
    // The signup gate (Phase 2 DoD "Gate toggle"). When true, registration
    // requires a valid invite code; when false, signup proceeds without one.
    // Default true — closed-beta posture.
    'invitation_required' => env('INVITE_REQUIRED', true),

    'codes' => [
        // Crockford Base32 — deliberately omits I L O U (confusables with
        // 1 / 0). 5 bits per character. Do NOT add the omitted letters back:
        // the generator refuses an alphabet containing them.
        'alphabet' => '0123456789ABCDEFGHJKMNPQRSTVWXYZ',

        // Default body length for random codes (40 bits at length 8 — safe to
        // ~1e5 live codes). Mass-referral campaigns should request 10 (50 bits).
        'default_length' => (int) env('INVITE_CODE_LENGTH', 8),

        // Generate-then-check retries before surfacing collision_exhausted.
        // Expected attempts at a correctly-sized length is ~1.0.
        'max_attempts' => (int) env('INVITE_CODE_MAX_ATTEMPTS', 5),

        // Reserved vanity codes (system terms / route prefixes) — rejected
        // with vanity_reserved.
        'reserved' => ['ADMIN', 'API', 'ROOT', 'SYSTEM', 'NULL', 'TEST'],
    ],

    // High-entropy link token (Invitation.token) — bytes of CSPRNG entropy.
    // 32 bytes → 256 bits, well above the ≥128-bit contract floor.
    'token_bytes' => (int) env('INVITE_TOKEN_BYTES', 32),

    // Invitation default time-to-live in days.
    'invitation_ttl_days' => (int) env('INVITE_INVITATION_TTL_DAYS', 7),

    // Signed-code HMAC key (Phase 2 forward seam; Phase 6 hardens rotation).
    // Falls back to APP_KEY-derived material when unset so dev never emits an
    // unsigned code; production MUST set a dedicated secret.
    'signing_key' => env('INVITE_SIGNING_KEY'),

    // PII handling (docs/15-security-privacy.md). ip / fingerprint are stored
    // as salted HMACs, never plaintext. retention_days drives the Phase 6
    // anonymization sweep.
    'pii' => [
        'hash_salt' => env('INVITE_PII_SALT'),
        'retention_days' => (int) env('INVITE_PII_RETENTION_DAYS', 90),
        // Whether to persist network fields at all. Off by default — they are
        // only kept when abuse review needs them (Phase 4 enables per-need).
        'store_network_fields' => (bool) env('INVITE_STORE_NETWORK_FIELDS', false),
    ],

    // Session key the deferred-redemption flow parks a guest's code under.
    'pending_session_key' => 'invite.pending_redemption',

    // Anti-abuse (docs/10-anti-abuse.md). Advisory gate — fail-open by design:
    // a detector error NEVER blocks; seat safety comes from the atomic claim.
    'anti_abuse' => [
        'enabled' => (bool) env('INVITE_ANTI_ABUSE_ENABLED', true),

        // Scoring → action thresholds (subject rolling totals). Hard-block
        // signals (blacklist, honeypot) short-circuit to block regardless.
        'thresholds' => [
            'flag' => 25,      // 25–49  → flag (proceed, record warn)
            'throttle' => 50,  // 50–79  → throttle (generic rate_limited + retry_after)
            'block' => 80,     // ≥80    → block (generic rate_limited)
        ],
        'retry_after' => (int) env('INVITE_ABUSE_RETRY_AFTER', 900),

        // Per-subject velocity: max prior redemptions allowed inside the window.
        'velocity' => [
            'account' => ['max' => 5, 'window' => 86400, 'score' => 30],
            'ip' => ['max' => 10, 'window' => 3600, 'score' => 25],
            'fingerprint' => ['max' => 8, 'window' => 3600, 'score' => 30],
        ],

        // Disposable-email domains (domain-only check). score 40; escalates to
        // block on reward/referral campaigns, flag otherwise.
        'disposable_domains' => ['mailinator.com', 'tempmail.com', 'guerrillamail.com', '10minutemail.com'],
        'disposable_score' => 40,

        // Manual blocklist (hard-block, score 100). ips are matched on the
        // salted hash; emails/domains on the canonical value; accounts on id.
        'blocklist' => [
            'ip_hashes' => [],
            'emails' => [],
            'domains' => [],
            'accounts' => [],
        ],

        // False-positive allowlist — skips scoring entirely (corporate proxy /
        // shared-IP escape hatch). Audited on change.
        'allowlist' => [
            'ips' => [],
            'domains' => [],
            'accounts' => [],
        ],
    ],
];
