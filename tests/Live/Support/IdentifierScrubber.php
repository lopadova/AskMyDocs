<?php

declare(strict_types=1);

namespace Tests\Live\Support;

/**
 * Deterministic scrubber for vendor-internal identifiers in recorded
 * live fixtures.
 *
 * Replaces each input identifier with a deterministic
 * `sha256-<8-char-hex>` prefix so:
 *
 *   - The same input identifier ALWAYS resolves to the same scrubbed
 *     output across a single recording session — cross-fixture
 *     references (e.g. a Notion block referencing its parent page)
 *     stay consistent so the fixture remains internally valid.
 *
 *   - Real production identifiers never leak into the committed test
 *     fixtures.
 *
 * Stateless and idempotent: hash() with the same salt always produces
 * the same output. The salt is a build-time constant (NOT secret) —
 * the scrubber is for fixture sanitisation, NOT cryptographic
 * concealment.
 */
final class IdentifierScrubber
{
    private const SALT = 'askmydocs-v4.5-live-fixture-scrub';

    public function scrubId(string $original): string
    {
        $hash = substr(hash('sha256', self::SALT . '|' . $original), 0, 8);
        return 'sha256-' . $hash;
    }

    /**
     * Walk a JSON-ish payload and replace every value at known
     * id-bearing keys with the scrubbed equivalent. Catches the
     * common shapes — `id`, `*_id`, `page_id`, `block_id`, etc. —
     * without trying to be exhaustive. The runbook's verification
     * one-liner instructs operators to spot-check the recorded
     * fixture before commit.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function scrubPayload(array $payload): array
    {
        $idLikeKeys = [
            'id', 'guid', 'sub', 'subscription_id',
            'page_id', 'block_id', 'database_id', 'workspace_id',
            'space_id', 'cloud_id', 'note_id', 'file_id', 'item_id',
            'parent_id', 'created_by_id', 'last_edited_by_id',
            'collection_id', 'notebook_id',
        ];

        return $this->walk($payload, $idLikeKeys);
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $idKeys
     * @return array<mixed>
     */
    private function walk(array $node, array $idKeys): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->walk($value, $idKeys);
                continue;
            }
            if (is_string($value) && is_string($key) && in_array($key, $idKeys, true) && $value !== '') {
                $out[$key] = $this->scrubId($value);
                continue;
            }
            // *_id suffix catch-all for vendor-specific id fields we
            // haven't enumerated in $idKeys yet.
            if (is_string($value) && is_string($key) && str_ends_with($key, '_id') && $value !== '') {
                $out[$key] = $this->scrubId($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
