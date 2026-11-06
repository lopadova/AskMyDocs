<?php

declare(strict_types=1);

namespace App\Billing;

use InvalidArgumentException;

/**
 * Computes invoice totals following the VAT rounding standard (STD-BIL-005).
 *
 * All amounts are integer cents. VAT is computed once per rate bucket on the
 * summed taxable amount and rounded half-up; it is never computed per line.
 */
final class InvoiceCalculator
{
    /** Supported Italian VAT rates, in percent. */
    public const VAT_RATES = [22, 10, 4];

    /**
     * @param array<int, array{qty:int, unit_cents:int, discount_pct:int, vat_rate:int}> $lines
     * @return array{net_cents:int, vat_cents:int, gross_cents:int, by_rate:array<int, array{net_cents:int, vat_cents:int}>}
     */
    public function totals(array $lines): array
    {
        $buckets = [];
        foreach ($lines as $line) {
            $this->assertKnownRate($line['vat_rate']);
            $lineCents = $line['qty'] * $line['unit_cents'];
            $net = $this->netAfterDiscount($lineCents, $line['discount_pct']);
            $buckets[$line['vat_rate']] = ($buckets[$line['vat_rate']] ?? 0) + $net;
        }

        $byRate = [];
        $net = 0;
        $vat = 0;
        foreach ($buckets as $rate => $bucketNet) {
            $bucketVat = $this->vatForBucket($bucketNet, $rate);
            $byRate[$rate] = ['net_cents' => $bucketNet, 'vat_cents' => $bucketVat];
            $net += $bucketNet;
            $vat += $bucketVat;
        }
        ksort($byRate);

        return ['net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net + $vat, 'by_rate' => $byRate];
    }

    /**
     * Applies a percentage discount to a line amount before any VAT is considered.
     */
    public function netAfterDiscount(int $lineCents, int $discountPct): int
    {
        if ($discountPct < 0 || $discountPct > 100) {
            throw new InvalidArgumentException('Discount must be between 0 and 100 percent.');
        }

        return $this->roundHalfUp($lineCents * (100 - $discountPct), 100);
    }

    /**
     * VAT for one rate bucket: net * rate / 100, rounded half-up to the cent.
     */
    public function vatForBucket(int $netCents, int $ratePct): int
    {
        return $this->roundHalfUp($netCents * $ratePct, 100);
    }

    /**
     * Integer division rounding halves away from zero, so credit notes
     * (negative amounts) mirror the invoice they correct.
     */
    public function roundHalfUp(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;
        $abs = abs($numerator);

        return $sign * intdiv($abs * 2 + $denominator, $denominator * 2);
    }

    private function assertKnownRate(int $rate): void
    {
        if (!in_array($rate, self::VAT_RATES, true)) {
            throw new InvalidArgumentException("Unsupported VAT rate: {$rate}");
        }
    }
}
