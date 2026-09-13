<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

final readonly class OcrResult
{
    /**
     * @param  list<OcrPage>  $pages
     * @param  array<string, mixed>  $meta  driver-specific facts (engine version, model…)
     */
    public function __construct(
        public string $driver,
        public array $pages,
        public array $meta = [],
    ) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function meanConfidence(): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (OcrPage $p): ?float => $p->confidence, $this->pages),
            static fn (?float $c): bool => $c !== null,
        ));
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 4);
    }

    public function minConfidence(): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (OcrPage $p): ?float => $p->confidence, $this->pages),
            static fn (?float $c): bool => $c !== null,
        ));

        return $values === [] ? null : round(min($values), 4);
    }

    public function figureCount(): int
    {
        return array_sum(array_map(static fn (OcrPage $p): int => count($p->figures), $this->pages));
    }
}
