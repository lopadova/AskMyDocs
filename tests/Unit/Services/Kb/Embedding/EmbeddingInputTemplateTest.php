<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Embedding;

use App\Services\Kb\Embedding\EmbeddingInputTemplate;
use App\Services\Kb\Pipeline\ChunkDraft;
use Tests\TestCase;

/**
 * v8.42/W2 (ADR 0035 §D2) — the embedding input template and its fingerprint,
 * in BOTH flag states (R43).
 */
final class EmbeddingInputTemplateTest extends TestCase
{
    private function template(): EmbeddingInputTemplate
    {
        return $this->app->make(EmbeddingInputTemplate::class);
    }

    /** @return list<ChunkDraft> */
    private function drafts(): array
    {
        return [
            new ChunkDraft(text: 'Il timeout è 30 secondi.', order: 0, headingPath: 'Magazzino Milano > API', metadata: []),
            new ChunkDraft(text: 'Intro senza sezione.', order: 1, headingPath: '', metadata: []),
        ];
    }

    public function test_off_returns_the_bare_chunk_text_byte_for_byte(): void
    {
        config(['kb.embedding_input.contextual' => false]);

        $this->assertSame(
            ['Il timeout è 30 secondi.', 'Intro senza sezione.'],
            $this->template()->inputs('p', 'Titolo', 'a/b.md', 'decision', $this->drafts()),
        );
        $this->assertSame('raw', $this->template()->templateId());
    }

    public function test_on_prefixes_title_and_section_and_keeps_the_chunk_text_last(): void
    {
        config(['kb.embedding_input.contextual' => true]);

        $out = $this->template()->inputs('p', 'Manuale Magazzino', 'a/b.md', null, $this->drafts());

        $this->assertSame('title: Manuale Magazzino | section: Magazzino Milano > API | text: Il timeout è 30 secondi.', $out[0]);
        $this->assertSame('title: Manuale Magazzino | section: none | text: Intro senza sezione.', $out[1], 'an empty heading reads "none"');
        $this->assertSame('ctx-v1', $this->template()->templateId());
    }

    public function test_canonical_type_is_inserted_before_the_text(): void
    {
        config(['kb.embedding_input.contextual' => true]);

        $out = $this->template()->inputs('p', 'T', 'a.md', 'runbook', $this->drafts());

        $this->assertStringContainsString('| type: runbook | text: ', $out[0]);
    }

    public function test_the_same_sentence_in_two_documents_embeds_to_different_inputs(): void
    {
        config(['kb.embedding_input.contextual' => true]);
        $same = [new ChunkDraft(text: 'Il timeout è 30 secondi.', order: 0, headingPath: 'Timeout', metadata: [])];

        $a = $this->template()->inputs('p', 'Magazzino Milano', 'm.md', null, $same);
        $b = $this->template()->inputs('p', 'Magazzino Bologna', 'b.md', null, $same);

        $this->assertNotSame($a[0], $b[0], 'context is what disambiguates identical sentences');
    }

    public function test_header_fragments_are_single_line_and_bounded(): void
    {
        config(['kb.embedding_input.contextual' => true]);
        $title = "Line one\n\nLine   two\t".str_repeat('x', 500);

        $out = $this->template()->inputs('p', $title, 'a.md', null, [new ChunkDraft('body', 0, "A\nB", [])]);

        $this->assertStringNotContainsString("\n", explode(' | text: ', $out[0])[0]);
        $this->assertLessThanOrEqual(200 + 30, mb_strlen(explode(' | section: ', $out[0])[0]));
        $this->assertStringContainsString('section: A B', $out[0]);
    }

    public function test_a_code_chunk_uses_the_file_symbol_language_template(): void
    {
        config(['kb.embedding_input.contextual' => true]);
        $code = [new ChunkDraft('return $x * 2;', 0, 'src/Billing/Invoice.php › class Invoice › total()', ['language' => 'php'])];

        $out = $this->template()->inputs('p', 'Invoice.php', 'src/Billing/Invoice.php', null, $code);

        $this->assertSame('file: src/Billing/Invoice.php | symbol: src/Billing/Invoice.php › class Invoice › total() | lang: php | code: return $x * 2;', $out[0]);
    }

    public function test_empty_drafts_yield_empty_inputs_in_both_states(): void
    {
        foreach ([false, true] as $flag) {
            config(['kb.embedding_input.contextual' => $flag]);
            $this->assertSame([], $this->template()->inputs('p', 't', 's', null, []));
        }
    }

    public function test_fingerprint_is_stable_16_hex_and_sensitive_to_every_recipe_input(): void
    {
        config(['kb.embedding_input.contextual' => false]);
        $base = $this->template()->fingerprint('openai', 'text-embedding-3-small');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $base);
        $this->assertSame($base, $this->template()->fingerprint('openai', 'text-embedding-3-small'), 'deterministic');

        $this->assertNotSame($base, $this->template()->fingerprint('openai', 'text-embedding-3-large'), 'model');
        $this->assertNotSame($base, $this->template()->fingerprint('regolo', 'text-embedding-3-small'), 'provider');

        config(['kb.chunking.target_tokens' => 256]);
        $this->assertNotSame($base, $this->template()->fingerprint('openai', 'text-embedding-3-small'), 'target tokens');
        config(['kb.chunking.target_tokens' => 512, 'kb.chunking.overlap_tokens' => 0]);
        $this->assertNotSame($base, $this->template()->fingerprint('openai', 'text-embedding-3-small'), 'overlap tokens');
        config(['kb.chunking.overlap_tokens' => 64, 'kb.chunking.hard_cap_tokens' => 2048]);
        $this->assertNotSame($base, $this->template()->fingerprint('openai', 'text-embedding-3-small'), 'hard cap');

        config(['kb.chunking.hard_cap_tokens' => 1024, 'kb.embedding_input.contextual' => true]);
        $this->assertNotSame($base, $this->template()->fingerprint('openai', 'text-embedding-3-small'), 'a template flip is detected as drift');

        config(['kb.embedding_input.template_version' => 2]);
        $v2 = $this->template()->fingerprint('openai', 'text-embedding-3-small');
        config(['kb.embedding_input.template_version' => 1]);
        $this->assertNotSame($v2, $this->template()->fingerprint('openai', 'text-embedding-3-small'), 'template version');
    }
}
