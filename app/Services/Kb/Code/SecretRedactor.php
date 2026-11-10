<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * Credential redaction for source code (v8.45 / W5, ADR 0035 D7).
 *
 * Source code is the one content class that routinely contains live secrets,
 * and the PII redactor (a prose/identity tool) does not see them. This pass
 * replaces known credential formats and high-entropy values assigned to
 * secret-like names with `[REDACTED_SECRET]` BEFORE the text is converted,
 * chunked, embedded or persisted, so a secret reaches neither the vector store
 * nor the stored conversion artifact.
 *
 * Two families, deliberately conservative on the second so ordinary code is
 * not mangled:
 *  - fixed formats (cloud / VCS / payment / LLM provider keys, JWTs, PEM
 *    private-key blocks, `scheme://user:password@host` credentials);
 *  - `name = "literal"` assignments where the name looks like a secret AND the
 *    literal is long, space-free, not an env-var name / dotted config key and
 *    has Shannon entropy ≥ 3.0 bits per character.
 *
 * Only a COUNT leaves this class (SEC-LOG-001): matched values are never
 * logged, returned or kept. Idempotent — redacting redacted text is a no-op.
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[REDACTED_SECRET]';

    /** @var list<string> fixed-format patterns replaced wholesale */
    private const FIXED_PATTERNS = [
        // PEM private-key blocks (multi-line).
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/s',
        // AWS access key ids.
        '/\b(?:AKIA|ASIA|AGPA|AIDA|AROA)[0-9A-Z]{16}\b/',
        // GitHub tokens.
        '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        '/\bgithub_pat_[A-Za-z0-9_]{22,}\b/',
        // Slack tokens.
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
        // Stripe keys.
        '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        // Google API keys.
        '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        // OpenAI / Anthropic style provider keys.
        '/\bsk-(?:ant-|proj-)?[A-Za-z0-9_\-]{20,}\b/',
        // JSON Web Tokens.
        '/\beyJ[A-Za-z0-9_\-]{10,}\.eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/',
    ];

    /** scheme://user:password@host — only the password is replaced. */
    private const URL_CREDENTIALS = '~\b([a-z][a-z0-9+.\-]*://[^\s:/@\'"]+:)([^\s@/\'"]{3,})(@)~i';

    /** name (quote?) = / : / => (quote) literal (quote) */
    private const SECRET_ASSIGNMENT = '~(?<name>[A-Za-z0-9_.\-\[\]\x27"]*(?:secret|passw(?:or)?d|passwd|pwd|token|api[_\-]?key|apikey|private[_\-]?key|access[_\-]?key|client[_\-]?secret|auth(?:orization)?[_\-]?key)[A-Za-z0-9_.\-\[\]\x27"]*)(?<op>\s*(?:=>|:=|=|:)\s*)(?<q>[\x27"`])(?<value>[^\x27"`\s]{12,})(?P=q)~i';

    private const MIN_ENTROPY = 3.0;

    /**
     * @return array{text: string, count: int}
     */
    public function redact(string $text): array
    {
        if ($text === '') {
            return ['text' => $text, 'count' => 0];
        }
        $count = 0;

        foreach (self::FIXED_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::PLACEHOLDER, $text, -1, $n);
            if ($replaced !== null) {
                $text = $replaced;
                $count += $n;
            }
        }

        $replaced = preg_replace_callback(self::URL_CREDENTIALS, static function (array $m) use (&$count): string {
            if ($m[2] === self::PLACEHOLDER) {
                return $m[0];
            }
            $count++;

            return $m[1].self::PLACEHOLDER.$m[3];
        }, $text);
        if ($replaced !== null) {
            $text = $replaced;
        }

        $replaced = preg_replace_callback(self::SECRET_ASSIGNMENT, function (array $m) use (&$count): string {
            if (! $this->looksLikeSecretLiteral($m['value'])) {
                return $m[0];
            }
            $count++;

            return $m['name'].$m['op'].$m['q'].self::PLACEHOLDER.$m['q'];
        }, $text);
        if ($replaced !== null) {
            $text = $replaced;
        }

        return ['text' => $text, 'count' => $count];
    }

    private function looksLikeSecretLiteral(string $value): bool
    {
        if (str_contains($value, 'REDACTED_SECRET')) {
            return false;
        }
        // An env-var NAME ('OPENAI_API_KEY') or a dotted config key
        // ('services.openai.key') is a reference, not a credential.
        if (preg_match('/^[A-Z][A-Z0-9_]+$/', $value) === 1) {
            return false;
        }
        if (preg_match('/^[a-z_][a-z0-9_]*(?:[.\-][a-z_][a-z0-9_]*)+$/i', $value) === 1 && ! preg_match('/\d{3,}/', $value)) {
            return false;
        }
        // Obvious placeholders.
        if (preg_match('/^(?:your[_\-]|<|\{|\$|%|x{4,}|\*{4,}|changeme|example|placeholder|dummy|sample|test[_\-]?(?:key|token|secret)|null|none|undefined)/i', $value) === 1) {
            return false;
        }

        return $this->entropy($value) >= self::MIN_ENTROPY;
    }

    private function entropy(string $value): float
    {
        $length = strlen($value);
        if ($length === 0) {
            return 0.0;
        }
        $entropy = 0.0;
        foreach (count_chars($value, 1) as $freq) {
            $p = $freq / $length;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }
}
