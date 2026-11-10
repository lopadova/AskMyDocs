<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * An invoice groups line items and computes its totals.
 */
final class Invoice
{
    public function __construct(private readonly Customer $customer, private readonly Collection $lines) {}

    /**
     * Sum of every line before tax.
     */
    public function subtotal(): int
    {
        return (int) $this->lines->sum(fn (array $line): int => $line['qty'] * $line['unit_price']);
    }

    public function tax(): int
    {
        return (int) round($this->subtotal() * $this->customer->taxRate());
    }

    public function total(): int
    {
        return $this->subtotal() + $this->tax();
    }
}

function format_money(int $cents): string
{
    return number_format($cents / 100, 2);
}
