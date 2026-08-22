<?php

namespace App\Services\Checkout;

use App\Models\PaymentSetting;
use App\Models\Product;
use App\Services\Delivery\DeliveryQuoter;

/**
 * Turns a basket into what the customer actually has to act on: one amount,
 * paid once, to Direct2Kariakoo.
 *
 * This used to group the basket by seller and hand back a till number for each
 * one. A customer buying from three shops was shown three numbers and asked to
 * make three transfers — which nobody does, and which leaves an order that
 * cannot be reconciled because it was paid in pieces to people D2K has no
 * record of receiving from.
 *
 * The vendor grouping is still built, because the customer should see which
 * shop each item comes from and sellers are still paid per line. But there is
 * exactly one payment recipient, and it is the marketplace's own.
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
                'vendor_id'   => $product->vendor?->id,
                'vendor_name' => $product->vendor?->business_name ?: 'Unknown seller',
                'items'       => [],
                'subtotal'    => 0.0,
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

        $delivery  = $this->quoter->quote($latitude, $longitude);
        $recipient = PaymentSetting::current();

        $vendorGroups = array_values(array_map(
            fn (array $group) => [
                'vendor_id'   => $group['vendor_id'],
                'vendor_name' => $group['vendor_name'],
                'subtotal'    => round($group['subtotal'], 2),
                'items'       => $group['items'],
            ],
            $groups,
        ));

        $total = round($subtotal + (float) ($delivery->amount ?? 0), 2);

        return [
            'currency' => 'TZS',
            'subtotal' => round($subtotal, 2),
            'delivery' => $delivery->toArray(),

            // Goods plus whatever delivery has been settled at. While delivery
            // is pending this is the goods total and the storefront says as
            // much — it never quietly folds in a placeholder fee.
            'total'    => $total,

            // One recipient for the whole basket, however many sellers are in
            // it. This is the amount the customer sends, in one payment.
            'payment'  => $recipient->toRecipientArray() + ['amount' => $total],

            // Kept so the customer can see which shop each item comes from and
            // what each is owed. It is presentation, not payment routing.
            'vendor_groups' => $vendorGroups,

            // Nothing about a seller can block a sale any more; the only thing
            // that can is the marketplace having no till configured.
            'can_place_order' => $vendorGroups !== [] && $missing === [] && $recipient->isPayable(),

            'payment_unavailable' => ! $recipient->isPayable(),
            'missing_products'    => $missing,
        ];
    }
}
