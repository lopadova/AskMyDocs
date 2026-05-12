<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Services\Kb\Retrieval\PreambleMatchDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PreambleMatchDetectorTest extends TestCase
{
    #[Test]
    public function detects_english_status_questions(): void
    {
        $d = new PreambleMatchDetector();
        $this->assertSame(1.0, $d->score("What's the status of cache project?"));
        $this->assertSame(1.0, $d->score('What is the status of the migration?'));
    }

    #[Test]
    public function detects_owner_and_when_questions(): void
    {
        $d = new PreambleMatchDetector();
        $this->assertSame(1.0, $d->score('Who owns the cache eviction policy?'));
        $this->assertSame(1.0, $d->score('When was the migration started?'));
    }

    #[Test]
    public function detects_italian_property_questions(): void
    {
        $d = new PreambleMatchDetector();
        $this->assertSame(1.0, $d->score('Qual è lo stato del progetto cache?'));
    }

    #[Test]
    public function returns_zero_for_regular_content_questions(): void
    {
        $d = new PreambleMatchDetector();
        $this->assertSame(0.0, $d->score('Explain the cache eviction policy.'));
        $this->assertSame(0.0, $d->score('How does the LRU cache work?'));
        $this->assertSame(0.0, $d->score(''));
    }
}
