<?php

declare(strict_types=1);
namespace App\ApiTools;

final class DeterministicOperations
{
    public function __construct(private BindingResolver $resolver) {}
    public function execute(string $operation, array $args, array $config): mixed
    {
        $items = $args['items'] ?? [];
        return match ($operation) {
            'compose' => $args,
            'select' => $this->resolver->path($args['value'] ?? $args, (string) ($config['path'] ?? '')),
            'limit' => array_slice($this->list($items), 0, max(0, min(100, (int) ($config['count'] ?? 5)))),
            'filter' => array_values(array_filter($this->list($items), fn ($item) => $this->resolver->path($item, $config['path'] ?? '') === ($config['equals'] ?? null))),
            'sort' => $this->sort($this->list($items), $config),
            'join' => $this->join($this->list($args['left'] ?? []), $this->list($args['right'] ?? []), $config),
            default => throw new \DomainException('api_tool_operation_unknown'),
        };
    }
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) { throw new \DomainException('api_tool_list_required'); }
        return $value;
    }
    private function sort(array $items, array $config): array
    {
        // Stable decorate-sort preserves original index when values compare equal.
        $decorated = [];
        foreach ($items as $i => $item) { $decorated[] = ['i'=>$i,'value'=>$this->resolver->path($item,$config['path'] ?? ''),'item'=>$item]; }
        usort($decorated, fn ($a,$b) => (($a['value'] <=> $b['value']) * (($config['direction'] ?? 'asc') === 'desc' ? -1 : 1)) ?: $a['i'] <=> $b['i']);
        return array_column($decorated,'item');
    }
    private function join(array $left, array $right, array $config): array
    {
        $result = [];
        foreach ($left as $item) {
            $key = $this->resolver->path($item, $config['left_key'] ?? 'id');
            $matches = array_values(array_filter($right, fn ($r) => $this->resolver->path($r,$config['right_key'] ?? 'id') === $key));
            // Every match remains a list. Never choose the first match implicitly.
            $result[] = ['left'=>$item, 'matches'=>$matches];
        }
        return $result;
    }
}
