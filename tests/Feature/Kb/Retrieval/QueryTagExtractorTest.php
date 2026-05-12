<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Retrieval;

use App\Services\Kb\Retrieval\QueryTagExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class QueryTagExtractorTest extends TestCase
{
    #[Test]
    public function strips_stop_words_and_short_tokens(): void
    {
        $tags = (new QueryTagExtractor())->extract('What is the cache eviction policy');
        $this->assertNotContains('what', $tags);
        $this->assertNotContains('the', $tags);
        $this->assertNotContains('is', $tags);
        $this->assertContains('cache', $tags);
        $this->assertContains('eviction', $tags);
        $this->assertContains('policy', $tags);
    }

    #[Test]
    public function ranks_longer_tokens_first(): void
    {
        $tags = (new QueryTagExtractor())->extract('architecture decision record', maxTags: 3);
        $this->assertSame('architecture', $tags[0], 'longest token should rank first');
    }

    #[Test]
    public function honours_max_tags_cap(): void
    {
        $tags = (new QueryTagExtractor())->extract('one two three four five six seven', maxTags: 2);
        $this->assertCount(2, $tags);
    }

    #[Test]
    public function merges_recent_messages_into_candidate_pool(): void
    {
        $tags = (new QueryTagExtractor())->extract(
            query: 'cache',
            recentMessages: ['What about eviction policy?'],
            maxTags: 5,
        );
        $this->assertContains('cache', $tags);
        $this->assertContains('eviction', $tags);
        $this->assertContains('policy', $tags);
    }
}
