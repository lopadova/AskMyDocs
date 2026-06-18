<?php

declare(strict_types=1);

namespace Tests\Feature\Invite;

use App\Models\InviteCode;
use App\Services\Invite\CodeGenerator;
use App\Services\Invite\CodeNormalizer;
use App\Services\Invite\Support\CodeGenerationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 DoD — code generation: distinct normalized Crockford codes, zero
 * ambiguous characters, no UNIQUE(code) violation escaping to the caller,
 * and the signed-code round trip.
 */
final class CodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generator(): CodeGenerator
    {
        return app(CodeGenerator::class);
    }

    public function test_bulk_generation_yields_distinct_codes_with_no_ambiguous_chars(): void
    {
        $codes = $this->generator()->generateBatch(200, [], 10);

        $strings = array_map(static fn (InviteCode $c): string => $c->code, $codes);

        $this->assertCount(200, $strings);
        $this->assertCount(200, array_unique($strings), 'Codes must be distinct');

        foreach ($strings as $code) {
            $this->assertSame(10, strlen($code));
            $this->assertDoesNotMatchRegularExpression('/[ILOU]/', $code, 'No ambiguous chars allowed');
            $this->assertMatchesRegularExpression('/^[0-9A-Z]+$/', $code);
        }
    }

    public function test_normalizer_folds_confusables_and_strips_separators(): void
    {
        $n = new CodeNormalizer;

        $this->assertSame('1100ABC', $n->normalize('  il-o_0 abc '));
        $this->assertSame($n->normalize('ILO'), $n->normalize($n->normalize('ILO')), 'idempotent');
    }

    public function test_length_below_floor_is_rejected(): void
    {
        $this->expectException(CodeGenerationException::class);

        try {
            $this->generator()->generateRandom([], 3);
        } catch (CodeGenerationException $e) {
            $this->assertSame('length_too_short', $e->errorCode);
            throw $e;
        }
    }

    public function test_vanity_reserved_word_is_rejected(): void
    {
        try {
            $this->generator()->mintVanity('ADMIN');
            $this->fail('Expected reserved rejection');
        } catch (CodeGenerationException $e) {
            $this->assertSame('vanity_reserved', $e->errorCode);
        }
    }

    public function test_vanity_taken_when_code_already_exists(): void
    {
        $this->generator()->mintVanity('TEAMX');

        try {
            $this->generator()->mintVanity('TEAMX');
            $this->fail('Expected taken rejection');
        } catch (CodeGenerationException $e) {
            $this->assertSame('vanity_taken', $e->errorCode);
        }
    }

    public function test_signed_code_round_trips_and_detects_tampering(): void
    {
        $exp = now()->addDay()->getTimestamp();
        $code = $this->generator()->mintSigned([
            'campaign' => 'beta',
            'capacity' => 5,
            'exp' => $exp,
        ]);

        $verified = $this->generator()->verifySigned($code->code);
        $this->assertTrue($verified['ok']);
        $this->assertSame('beta', $verified['payload']['campaign']);

        // Flip a character → signature mismatch.
        $tampered = $code->code[0] === '0' ? '1' . substr($code->code, 1) : '0' . substr($code->code, 1);
        $this->assertFalse($this->generator()->verifySigned($tampered)['ok']);
    }
}
