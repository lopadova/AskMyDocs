<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Retrieval;

use App\Services\Kb\Retrieval\QueryLanguageDetector;
use PHPUnit\Framework\TestCase;

/**
 * v8.8/W5 — `QueryLanguageDetector`: confident-or-null query-language
 * detection bounded by the supported set. R14 — an inconclusive signal returns
 * null so the caller stems with the configured default, never a wrong guess.
 */
final class QueryLanguageDetectorTest extends TestCase
{
    private QueryLanguageDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new QueryLanguageDetector();
    }

    public function test_detects_english(): void
    {
        $this->assertSame('english', $this->detector->detect('How do I rotate the signing key?', ['english', 'italian']));
    }

    public function test_detects_italian(): void
    {
        $this->assertSame('italian', $this->detector->detect('Come faccio a ruotare la chiave di firma?', ['english', 'italian']));
    }

    public function test_detects_french_when_supported(): void
    {
        $this->assertSame('french', $this->detector->detect('Comment puis-je faire pour changer la clé ?', ['english', 'french']));
    }

    public function test_returns_null_when_no_stopwords_match(): void
    {
        // No function words → no signal → caller falls back to default.
        $this->assertNull($this->detector->detect('rotate signing key', ['english', 'italian']));
    }

    public function test_returns_null_on_empty_query(): void
    {
        $this->assertNull($this->detector->detect('   ', ['english', 'italian']));
        $this->assertNull($this->detector->detect('', ['english', 'italian']));
    }

    public function test_only_considers_supported_languages(): void
    {
        // An Italian query but italian is NOT supported → no italian stopwords
        // counted; english has none either → null (fall back to default).
        $this->assertNull($this->detector->detect('come va il sistema', ['english']));
    }

    public function test_detected_language_must_be_in_supported_set(): void
    {
        // French query, but only english+italian supported → french can't win.
        $result = $this->detector->detect('comment ça marche le système', ['english', 'italian']);
        $this->assertNotSame('french', $result);
    }

    public function test_unknown_dictionary_in_supported_is_ignored(): void
    {
        // 'klingon' has no stopword table — ignored, italian still wins.
        $this->assertSame('italian', $this->detector->detect('come sono le cose', ['klingon', 'italian']));
    }
}
