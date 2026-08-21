<?php

namespace App\Services\Delivery;

/**
 * What delivery costs for one order — or the honest admission that nobody
 * knows yet.
 *
 * The distinction matters at checkout. `amount === null` is not "free" and
 * not "zero": it means the fee has not been determined, and the storefront
 * says so instead of printing a figure the rider will contradict.
 */
final class DeliveryQuote
{
    private function __construct(
        public readonly ?float $amount,
        public readonly ?float $distanceKm,
        public readonly string $reason,
    ) {
    }

    /** A fee worked out from the customer's distance. */
    public static function quoted(float $amount, ?float $distanceKm = null): self
    {
        return new self(round($amount, 2), $distanceKm, 'quoted');
    }

    /**
     * No fee could be worked out.
     *
     * `$reason` records why — no rate card configured, or no destination
     * coordinates on the order — so the storefront can explain the wait and a
     * support agent can tell the two cases apart.
     */
    public static function pending(string $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isPending(): bool
    {
        return $this->amount === null;
    }

    /** The shape the storefront and the Flutter app both read. */
    public function toArray(): array
    {
        return [
            'status'      => $this->isPending() ? 'pending' : 'quoted',
            'amount'      => $this->amount,
            'distance_km' => $this->distanceKm,
            'reason'      => $this->isPending() ? $this->reason : null,
        ];
    }
}
