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

    public function test_unsupported_language_query_returns_null_not_a_wrong_guess(): void
    {
        // A French query with only english+italian supported must return NULL
        // (fall back to default) — NOT silently mis-detect as italian/english
        // because a shared article happened to collide (curated stopwords +
        // confident-winner guard; Copilot review).
        $this->assertNull($this->detector->detect('comment ça marche le système', ['english', 'italian']));
        // A Spanish query, same supported set → also null.
        $this->assertNull($this->detector->detect('cómo funciona el sistema', ['english', 'italian']));
    }

    public function test_shared_articles_do_not_force_a_false_positive(): void
    {
        // 'la' / 'le' / 'un' are shared across it/fr/es and are deliberately
        // NOT in the stopword sets, so a query of only shared articles is
        // inconclusive → null.
        $this->assertNull($this->detector->detect('la le un', ['english', 'italian', 'french']));
    }

    public function test_supported_languages_are_case_and_whitespace_normalized(): void
    {
        // A caller passing `English` / ` Italian ` must not silently disable
        // detection (regconfig names are lowercase).
        $this->assertSame('english', $this->detector->detect('How does this work?', ['English', ' Italian ']));
        $this->assertSame('italian', $this->detector->detect('Come funziona questo?', ['ENGLISH', 'Italian']));
    }

    public function test_unknown_dictionary_in_supported_is_ignored(): void
    {
        // 'klingon' has no stopword table — ignored, italian still wins.
        $this->assertSame('italian', $this->detector->detect('come sono questi', ['klingon', 'italian']));
    }
}
