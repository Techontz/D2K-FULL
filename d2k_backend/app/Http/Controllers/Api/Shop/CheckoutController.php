<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Services\Checkout\CheckoutBuilder;
use App\Services\Delivery\DeliveryDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the customer is about to agree to, priced by the same code that will
 * price the order itself.
 *
 * The storefront used to compute its own total from a constant it kept in
 * sync with the backend by hand. That is how a customer ends up seeing one
 * figure and being charged another. Checkout now asks the server, and the
 * server is the only thing that knows the answer.
 */
class CheckoutController extends Controller
{
    public function __construct(private CheckoutBuilder $builder)
    {
    }

    /**
     * Price a basket and say who is being paid.
     *
     * Read-only: nothing is reserved, no stock moves, and it is safe to call
     * on every edit to the basket or the delivery pin.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'              => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:99'],
            // {"Size": "M"} — validated against the product by the builder.
            'items.*.options'    => ['sometimes', 'nullable'],

            // The destination pin, when the customer has dropped one. Delivery
            // is quoted from it; without it the fee stays pending.
            'delivery_latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // A saved address (the caller's own) and/or a campus. A campus
            // with a flat delivery fee is charged that fee.
            'address_id'         => ['nullable', 'integer'],
            'campus_id'          => ['nullable', 'integer'],
        ]);

        $destination = DeliveryDestination::fromInput($data, $request->user());

        return response()->json(
            $this->builder->build(
                $data['items'],
                $destination->latitude,
                $destination->longitude,
                $destination,
            )
        );
    }
}
