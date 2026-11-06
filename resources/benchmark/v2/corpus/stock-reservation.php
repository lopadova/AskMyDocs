<?php

declare(strict_types=1);

namespace App\Warehouse;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;

/**
 * In-memory model of stock reservations with a per-channel time to live.
 *
 * Web carts hold stock for 15 minutes, phone orders for 4 hours and B2B
 * orders for 48 hours (DEC-WH-007). A reservation can be extended once.
 */
final class StockReservation
{
    public const CHANNEL_WEB_CART = 'web_cart';
    public const CHANNEL_PHONE = 'phone';
    public const CHANNEL_B2B = 'b2b';
    public const EXTENSION_SECONDS = 600;
    public const MAX_CUSTOMER_SHARE_PERCENT = 20;

    private const TTL_SECONDS = [
        self::CHANNEL_WEB_CART => 900,
        self::CHANNEL_PHONE => 14_400,
        self::CHANNEL_B2B => 172_800,
    ];

    /** @var array<string, array{sku:string, qty:int, customer:string, expires:DateTimeImmutable, extended:bool}> */
    private array $reservations = [];

    /** @param array<string, int> $stockBySku on-hand quantity per SKU */
    public function __construct(private array $stockBySku)
    {
    }

    public function reserve(
        string $id,
        string $sku,
        int $qty,
        string $channel,
        string $customer,
        DateTimeImmutable $now,
    ): DateTimeImmutable {
        if (!isset(self::TTL_SECONDS[$channel])) {
            throw new RuntimeException("Unknown channel {$channel}");
        }
        if ($qty > $this->available($sku)) {
            throw new RuntimeException("Not enough stock for {$sku}");
        }
        $cap = intdiv(($this->stockBySku[$sku] ?? 0) * self::MAX_CUSTOMER_SHARE_PERCENT, 100);
        if ($this->reservedBy($customer, $sku) + $qty > $cap) {
            throw new RuntimeException('Customer exceeds the 20% share of this SKU');
        }

        $expires = $this->expiresAt($channel, $now);
        $this->reservations[$id] = compact('sku', 'qty', 'customer', 'expires') + ['extended' => false];

        return $expires;
    }

    public function expiresAt(string $channel, DateTimeImmutable $from): DateTimeImmutable
    {
        return $from->add(new DateInterval('PT' . self::TTL_SECONDS[$channel] . 'S'));
    }

    public function extendOnce(string $id): DateTimeImmutable
    {
        $row = $this->reservations[$id] ?? throw new RuntimeException("Unknown reservation {$id}");
        if ($row['extended']) {
            throw new RuntimeException('Reservation was already extended');
        }
        $row['expires'] = $row['expires']->add(new DateInterval('PT' . self::EXTENSION_SECONDS . 'S'));
        $row['extended'] = true;
        $this->reservations[$id] = $row;

        return $row['expires'];
    }

    /** Frees every reservation whose expiry is not after $now; returns how many were released. */
    public function releaseExpired(DateTimeImmutable $now): int
    {
        $released = 0;
        foreach ($this->reservations as $id => $row) {
            if ($row['expires'] > $now) {
                continue;
            }
            unset($this->reservations[$id]);
            $released++;
        }

        return $released;
    }

    public function available(string $sku): int
    {
        $held = array_sum(array_column(
            array_filter($this->reservations, fn (array $r): bool => $r['sku'] === $sku),
            'qty',
        ));

        return ($this->stockBySku[$sku] ?? 0) - $held;
    }

    private function reservedBy(string $customer, string $sku): int
    {
        $mine = array_filter($this->reservations, fn (array $r): bool => $r['customer'] === $customer && $r['sku'] === $sku);

        return array_sum(array_column($mine, 'qty'));
    }
}
