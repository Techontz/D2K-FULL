<?php

namespace App\Services\Delivery;

/**
 * Works out what delivery costs for a given destination.
 *
 * This replaces a flat TZS 3,000 that was applied to every order in the
 * country. Delivery to the next street and delivery to Mbagala are not the
 * same job, and charging both the same was either overcharging one customer
 * or underpaying the rider on the other.
 *
 * The rate card lives in `config/delivery.php` and ships empty. With nothing
 * configured this returns a *pending* quote rather than a number — the one
 * thing it will never do is guess. A guessed fee is worse than an absent one:
 * the customer commits to a total the rider then has to argue with.
 *
 * Distance is straight-line (haversine). Road distance would be better and
 * needs a routing provider; when one is added it belongs behind this same
 * method, so nothing downstream has to change.
 */
class DeliveryQuoter
{
    /** Mean radius of the Earth in kilometres. */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * Quote delivery to a destination pin.
     *
     * Both coordinates are optional because a customer may type an address
     * without dropping a map pin. That is a pending quote, not a free
     * delivery — the fee is settled once the destination is known.
     */
    public function quote(?float $latitude, ?float $longitude): DeliveryQuote
    {
        $rates = $this->rates();

        if ($rates === null) {
            return DeliveryQuote::pending('no_rate_card');
        }

        if (! $this->isCoordinate($latitude, $longitude)) {
            return DeliveryQuote::pending('no_destination');
        }

        $distance = $this->distanceKm(
            $rates['origin_lat'],
            $rates['origin_lng'],
            (float) $latitude,
            (float) $longitude,
        );

        $fee = $rates['base_fee'] + ($rates['per_km'] * $distance);

        $fee = max($fee, $rates['minimum_fee']);

        if ($rates['maximum_fee'] !== null) {
            $fee = min($fee, $rates['maximum_fee']);
        }

        return DeliveryQuote::quoted($fee, round($distance, 2));
    }

    /**
     * The configured rate card, or null when it is incomplete.
     *
     * Half a rate card is not usable: an origin with no per-kilometre rate
     * cannot price anything, and a per-kilometre rate with no origin has
     * nothing to measure from. Either the whole card is present or pricing
     * stays pending.
     */
    private function rates(): ?array
    {
        $originLat = config('delivery.origin.latitude');
        $originLng = config('delivery.origin.longitude');
        $baseFee   = config('delivery.base_fee');
        $perKm     = config('delivery.per_km');

        foreach ([$originLat, $originLng, $baseFee, $perKm] as $value) {
            if ($value === null || $value === '' || ! is_numeric($value)) {
                return null;
            }
        }

        if (! $this->isCoordinate((float) $originLat, (float) $originLng)) {
            return null;
        }

        $minimum = config('delivery.minimum_fee');
        $maximum = config('delivery.maximum_fee');

        return [
            'origin_lat'  => (float) $originLat,
            'origin_lng'  => (float) $originLng,
            'base_fee'    => (float) $baseFee,
            'per_km'      => (float) $perKm,
            'minimum_fee' => is_numeric($minimum) ? (float) $minimum : 0.0,
            'maximum_fee' => is_numeric($maximum) ? (float) $maximum : null,
        ];
    }

    /** Great-circle distance between two pins, in kilometres. */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * A usable pin.
     *
     * (0, 0) is in the Gulf of Guinea and is what an uninitialised map picker
     * sends, so it is treated as absent rather than as a destination four
     * thousand kilometres away.
     */
    private function isCoordinate(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        if (abs($latitude) < 0.0001 && abs($longitude) < 0.0001) {
            return false;
        }

        return abs($latitude) <= 90 && abs($longitude) <= 180;
    }
}
