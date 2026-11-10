<?php

declare(strict_types=1);

namespace App\Refunds;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Applies the current refund policy (DEC-REFUND-002) to a returned order line.
 *
 * Full refund up to day 30 after delivery, a partial refund capped at 50% of
 * the line total from day 31 to day 60, nothing afterwards.
 */
final class RefundService
{
    public const FULL_REFUND_DAYS = 30;
    public const PARTIAL_REFUND_DAYS = 60;
    public const PARTIAL_CAP_PERCENT = 50;
    public const FINANCE_APPROVAL_THRESHOLD_CENTS = 50_000;

    /**
     * Whole days elapsed between delivery and the refund request.
     */
    public function daysSinceDelivery(DateTimeImmutable $deliveredAt, DateTimeImmutable $now): int
    {
        if ($deliveredAt > $now) {
            throw new InvalidArgumentException('Delivery date is in the future.');
        }

        return (int) $deliveredAt->diff($now)->format('%a');
    }

    /**
     * Highest refundable amount for a line, given the days since delivery.
     */
    public function maxRefundCents(int $lineTotalCents, int $daysSinceDelivery): int
    {
        if ($daysSinceDelivery <= self::FULL_REFUND_DAYS) {
            return $lineTotalCents;
        }
        if ($daysSinceDelivery <= self::PARTIAL_REFUND_DAYS) {
            return intdiv($lineTotalCents * self::PARTIAL_CAP_PERCENT, 100);
        }

        return 0;
    }

    /**
     * Refunds above EUR 500 need a second approver from finance.
     */
    public function requiresFinanceApproval(int $refundCents): bool
    {
        return $refundCents > self::FINANCE_APPROVAL_THRESHOLD_CENTS;
    }

    /**
     * Shipping is refunded only together with a full refund of the line.
     */
    public function includesShipping(int $refundCents, int $lineTotalCents): bool
    {
        return $refundCents === $lineTotalCents;
    }

    /**
     * @return array{approved_cents:int, needs_finance:bool, shipping_refunded:bool}
     */
    public function request(
        int $lineTotalCents,
        int $requestedCents,
        DateTimeImmutable $deliveredAt,
        DateTimeImmutable $now,
    ): array {
        $days = $this->daysSinceDelivery($deliveredAt, $now);
        $approved = min($requestedCents, $this->maxRefundCents($lineTotalCents, $days));

        return [
            'approved_cents' => $approved,
            'needs_finance' => $this->requiresFinanceApproval($approved),
            'shipping_refunded' => $this->includesShipping($approved, $lineTotalCents),
        ];
    }
}
