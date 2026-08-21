<?php

namespace App\Services\Checkout;

use App\Models\Product;
use App\Models\Vendor;
use App\Services\Delivery\DeliveryQuoter;
use App\Support\LipaNamba;

/**
 * Turns a basket into the thing a customer actually has to act on: who they
 * are paying, what each of those sellers is owed, and where the money goes.
 *
 * A D2K basket routinely spans several stores, and each store has its own
 * till. Presenting one merged "pay this" figure would be wrong in the most
 * expensive way possible — the customer pays one seller for everyone's goods
 * and the rest are never paid at all. So the basket is grouped by vendor and
 * every group carries its own Lipa Namba.
 *
 * Used by the checkout preview and by the order guard, so the customer cannot
 * be shown one set of rules and then judged by another.
 */
class CheckoutBuilder
{
    public function __construct(private DeliveryQuoter $quoter)
    {
    }

    /**
     * Price and group a basket.
     *
     * `$items` is the validated `[['product_id' => n, 'quantity' => n], …]`
     * shape the storefront posts. The destination pin is optional: without it
     * delivery is quoted as pending rather than assumed free.
     */
    public function build(array $items, ?float $latitude = null, ?float $longitude = null): array
    {
        $products = Product::with('vendor')
            ->whereIn('id', collect($items)->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $groups   = [];
        $subtotal = 0.0;
        $missing  = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                $missing[] = (int) $item['product_id'];
                continue;
            }

            $quantity  = (int) $item['quantity'];
            $lineTotal = (float) $product->new_price * $quantity;
            $subtotal += $lineTotal;

            $vendorId = (int) $product->vendor_id;

            $groups[$vendorId] ??= [
                'vendor'   => $product->vendor,
                'items'    => [],
                'subtotal' => 0.0,
            ];

            $groups[$vendorId]['items'][] = [
                'product_id' => $product->id,
                'name'       => $product->name,
                'quantity'   => $quantity,
                'price'      => (float) $product->new_price,
                'total'      => $lineTotal,
            ];

            $groups[$vendorId]['subtotal'] += $lineTotal;
        }

        $delivery = $this->quoter->quote($latitude, $longitude);

        $vendorGroups = array_values(array_map(
            fn (array $group) => $this->presentGroup($group),
            $groups,
        ));

        $blocked = array_values(array_filter(
            array_map(fn (array $group) => $group['blocked_reason'], $vendorGroups),
        ));

        return [
            'currency'  => 'TZS',
            'subtotal'  => round($subtotal, 2),
            'delivery'  => $delivery->toArray(),

            // Products plus whatever delivery has been settled at. While
            // delivery is pending this is the goods total and the storefront
            // says as much — it never quietly folds in a placeholder fee.
            'total'     => round($subtotal + (float) ($delivery->amount ?? 0), 2),

            'vendor_groups'   => $vendorGroups,
            'can_place_order' => $vendorGroups !== [] && $blocked === [] && $missing === [],
            'issues'          => $blocked,
            'missing_products' => $missing,
        ];
    }

    /**
     * Why this seller cannot take an order, in words the customer can act on
     * — or null when they can.
     *
     * Both branches name the store, because a basket spanning four vendors
     * needs to say which one is holding things up.
     */
    public static function blockedReason(?Vendor $vendor): ?string
    {
        if (! $vendor) {
            return 'One of these products no longer has a seller attached.';
        }

        $name = $vendor->business_name ?: 'This seller';

        if (! $vendor->canPublish()) {
            return sprintf('%s is not currently accepting orders.', $name);
        }

        if (! $vendor->hasLipaNamba()) {
            return sprintf(
                '%s has not set up their Lipa Namba yet, so payment for their items cannot be completed. Remove them from your basket or contact the seller.',
                $name,
            );
        }

        return null;
    }

    private function presentGroup(array $group): array
    {
        /** @var Vendor|null $vendor */
        $vendor = $group['vendor'];
        $reason = self::blockedReason($vendor);

        // Only ever this vendor's own number, and only when it is real. A
        // group that cannot be paid reports null rather than an empty string,
        // so no caller can mistake "not set up" for "pay into nothing".
        $lipaNamba = $vendor && $vendor->hasLipaNamba()
            ? LipaNamba::normalise($vendor->lipa_namba)
            : null;

        return [
            'vendor_id'          => $vendor?->id,
            'vendor_name'        => $vendor?->business_name ?: 'Unknown seller',
            'lipa_namba'         => $lipaNamba,
            'lipa_namba_display' => LipaNamba::pretty($lipaNamba),
            'can_accept_orders'  => $reason === null,
            'blocked_reason'     => $reason,
            'subtotal'           => round($group['subtotal'], 2),
            'items'              => $group['items'],
        ];
    }
}
