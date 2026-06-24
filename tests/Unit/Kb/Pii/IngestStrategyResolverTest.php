<?php

declare(strict_types=1);

namespace Tests\Unit\Kb\Pii;

use App\Services\Kb\Pii\IngestStrategyResolver;
use InvalidArgumentException;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Tests\TestCase;

/**
 * v8.23 (Ciclo 4) — the shared mask/tokenise strategy-instance resolver used by
 * both the connector boundary and the inline ingestion path.
 */
final class IngestStrategyResolverTest extends TestCase
{
    private IngestStrategyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pii-redactor.salt' => 'resolver-test-salt']);
        $this->resolver = app(IngestStrategyResolver::class);
    }

    public function test_it_builds_the_mask_strategy(): void
    {
        $this->assertInstanceOf(MaskStrategy::class, $this->resolver->forName('mask'));
    }

    public function test_it_builds_the_tokenise_strategy(): void
    {
        $this->assertInstanceOf(TokeniseStrategy::class, $this->resolver->forName('tokenise'));
    }

    public function test_it_trims_incidental_whitespace(): void
    {
        $this->assertInstanceOf(MaskStrategy::class, $this->resolver->forName('  mask '));
        $this->assertInstanceOf(TokeniseStrategy::class, $this->resolver->forName(" tokenise\n"));
    }

    public function test_it_throws_loudly_on_a_real_typo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tokenize/');
        $this->resolver->forName('tokenize');
    }
}
