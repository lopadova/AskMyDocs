<?php

declare(strict_types=1);

namespace App\Services\Dev;

/** Synthetic records shared by the offline HTTP, generation and decision fixtures. */
final class AgentRetrievalFixture
{
    public const CUSTOMER_ID = 77;
    public const ORDERS = [
        ['number' => 'A-100', 'customer_id' => 77, 'total' => 120],
        ['number' => 'A-101', 'customer_id' => 77, 'total' => 80],
        ['number' => 'A-102', 'customer_id' => 77, 'total' => 45],
    ];
    public const QUESTIONS = ['Dammi tutti gli ordini di Tizio', 'Dammi gli ordini di Tizio e simula HTTP503'];
    public const POLICY_QUESTION = 'How many days per week can I work remotely?';
    public const POLICY = 'Employees may work remotely up to 3 days per week with manager approval.';
    public const POLICY_TITLES = ['Remote Work Policy', 'e2e-remote-work'];

    public static function orderText(array $record, bool $italian = true): string
    {
        return ($italian ? 'Ordine ' : 'Order ').$record['number'].($italian ? ': totale ' : ': total ').$record['total'].'.';
    }

    public static function matchOrder(mixed $value): ?array
    {
        if (! is_array($value)) return null;
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['customer_id', 'number', 'total']) return null;
        foreach (self::ORDERS as $order) {
            if (($value['number'] ?? null) === $order['number'] && ($value['customer_id'] ?? null) === $order['customer_id']
                && ($value['total'] ?? null) === $order['total']) return $order;
        }
        return null;
    }
}
