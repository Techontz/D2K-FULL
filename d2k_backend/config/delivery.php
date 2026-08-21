<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Delivery pricing
    |--------------------------------------------------------------------------
    |
    | Delivery is charged by distance, not at a flat rate. Until an operator
    | supplies the figures below, checkout quotes delivery as *pending* rather
    | than inventing a number: a customer must never be shown a total that the
    | rider will later contradict.
    |
    | Set every key in this block to switch distance pricing on. Leave any of
    | them unset and App\Services\Delivery\DeliveryQuoter reports "pending",
    | which is what the storefront and the order record then show.
    |
    | Superseded: a hardcoded TZS 3,000 charge, applied to every order
    | regardless of where it was going.
    |
    */

    // Where couriers set out from. Kariakoo market is the natural origin for
    // this marketplace, but it is configuration rather than a constant so a
    // second dispatch point does not require a code change.
    'origin' => [
        'latitude'  => env('DELIVERY_ORIGIN_LAT'),
        'longitude' => env('DELIVERY_ORIGIN_LNG'),
    ],

    // Charged on every delivery before distance is considered.
    'base_fee' => env('DELIVERY_BASE_FEE'),

    // Charged per kilometre of straight-line distance to the destination.
    'per_km' => env('DELIVERY_PER_KM'),

    // Floor, so a delivery two streets away still covers the rider's time.
    'minimum_fee' => env('DELIVERY_MINIMUM_FEE'),

    // Ceiling, so a bad coordinate cannot produce an absurd quote. Optional.
    'maximum_fee' => env('DELIVERY_MAXIMUM_FEE'),

];
