<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Canonical;

use App\Support\Canonical\EvidenceTier;
use PHPUnit\Framework\TestCase;

/** v8.11/P1b (AutoSci #67) — the evidence-strength taxonomy. */
final class EvidenceTierTest extends TestCase
{
    public function test_taxonomy_values(): void
    {
        $this->assertSame(
            ['guideline', 'peer_reviewed', 'official', 'preprint', 'news', 'blog', 'search_hint', 'unverified'],
            EvidenceTier::values(),
        );
    }

    public function test_ranks_are_strictly_descending_strong_to_weak(): void
    {
        $this->assertGreaterThan(EvidenceTier::PeerReviewed->rank(), EvidenceTier::Guideline->rank());
        $this->assertGreaterThan(EvidenceTier::Official->rank(), EvidenceTier::PeerReviewed->rank());
        $this->assertGreaterThan(EvidenceTier::Blog->rank(), EvidenceTier::News->rank());
        $this->assertGreaterThan(EvidenceTier::Unverified->rank(), EvidenceTier::SearchHint->rank());
    }

    public function test_low_confidence_set(): void
    {
        $this->assertTrue(EvidenceTier::Blog->isLowConfidence());
        $this->assertTrue(EvidenceTier::SearchHint->isLowConfidence());
        $this->assertTrue(EvidenceTier::Unverified->isLowConfidence());
        $this->assertFalse(EvidenceTier::Guideline->isLowConfidence());
        $this->assertFalse(EvidenceTier::PeerReviewed->isLowConfidence());
        $this->assertFalse(EvidenceTier::Official->isLowConfidence());
        $this->assertFalse(EvidenceTier::Preprint->isLowConfidence());
        $this->assertFalse(EvidenceTier::News->isLowConfidence());
    }

    public function test_try_from_loose_normalizes_and_rejects(): void
    {
        $this->assertSame(EvidenceTier::PeerReviewed, EvidenceTier::tryFromLoose('  Peer_Reviewed '));
        $this->assertSame(EvidenceTier::Guideline, EvidenceTier::tryFromLoose('guideline'));
        $this->assertNull(EvidenceTier::tryFromLoose('made-up-tier'));
        $this->assertNull(EvidenceTier::tryFromLoose(''));
        $this->assertNull(EvidenceTier::tryFromLoose(null));
        $this->assertNull(EvidenceTier::tryFromLoose(123));
    }
}
