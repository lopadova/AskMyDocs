<?php

declare(strict_types=1);

namespace App\Services\Digest\Renderers;

use InvalidArgumentException;

/**
 * v8.15/W2 — registry of digest card renderers (R23).
 *
 * Validates at construction that every registered entry implements
 * {@see DigestCardRendererInterface} and that no two renderers claim the same
 * `channel()` key (the mutex that prevents first-match-wins picking the wrong
 * renderer). Resolution is an explicit map lookup, not iteration order.
 */
final class DigestRendererRegistry
{
    /** @var array<string, DigestCardRendererInterface> */
    private array $byChannel = [];

    /**
     * @param  iterable<DigestCardRendererInterface>  $renderers
     */
    public function __construct(iterable $renderers)
    {
        foreach ($renderers as $renderer) {
            if (! $renderer instanceof DigestCardRendererInterface) {
                throw new InvalidArgumentException(
                    'Digest renderer must implement DigestCardRendererInterface: '.$renderer::class,
                );
            }

            $channel = $renderer->channel();
            if (isset($this->byChannel[$channel])) {
                throw new InvalidArgumentException(
                    "Duplicate digest renderer for channel `{$channel}`: ".
                    $this->byChannel[$channel]::class.' and '.$renderer::class,
                );
            }

            $this->byChannel[$channel] = $renderer;
        }
    }

    public function has(string $channel): bool
    {
        return isset($this->byChannel[$channel]);
    }

    public function for(string $channel): ?DigestCardRendererInterface
    {
        return $this->byChannel[$channel] ?? null;
    }

    /**
     * Resolve-and-use variant for callers that already know the channel is
     * registered (via {@see has()} / {@see channels()}); throws rather than
     * returning null so a future caller that skips the guard fails loudly.
     */
    public function forOrFail(string $channel): DigestCardRendererInterface
    {
        return $this->byChannel[$channel]
            ?? throw new InvalidArgumentException("No digest renderer registered for channel `{$channel}`.");
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->byChannel);
    }
}
