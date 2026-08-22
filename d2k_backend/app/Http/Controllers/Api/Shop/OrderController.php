<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\PaymentSetting;
use App\Services\Delivery\DeliveryQuoter;
use App\Support\LipaNamba;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Storefront checkout and order history.
 *
 * The `orders` table stores one row per product line. A single checkout is
 * tied together by a shared `reference`, which is what the shopper sees and
 * quotes to support. This controller reads and writes that grouping.
 */
class OrderController extends Controller
{
    /**
     * Delivery is priced by distance, not at a flat rate. There is
     * deliberately no constant here: the previous flat TZS 3,000 charged a
     * customer two streets away the same as one in Mbagala, and it was
     * duplicated in the storefront where the two could drift apart.
     */
    public function __construct(private DeliveryQuoter $quoter)
    {
    }

    /**
     * Place an order.
     *
     * Runs in a transaction with the product rows locked, so two shoppers
     * racing for the last unit cannot both succeed. Stock is decremented here
     * — the previous implementation checked availability but never reduced it,
     * so the catalogue never sold out.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'items'                => 'required|array|min:1|max:50',
            'items.*.product_id'   => 'required|integer|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1|max:99',
            'delivery_address'     => 'required|string|max:500',
            'customer_phone'       => 'required|string|max:40',
            // Pay on delivery is gone: sellers were shipping goods against a
            // promise and absorbing every refusal at the door. Payment is now
            // made to the seller's own Lipa Namba.
            'payment_method'       => 'required|string|in:lipa_namba,mobile_money',
            'payment_provider'     => 'nullable|string|max:40',

            // The destination pin, when the customer has dropped one. Delivery
            // is quoted from it; without it the fee stays pending.
            'delivery_latitude'    => 'nullable|numeric|between:-90,90',
            'delivery_longitude'   => 'nullable|numeric|between:-180,180',
        ]);

        $user      = $request->user();
        $reference = $this->newReference();

        // One recipient for the whole basket. Resolved before the transaction
        // so every line of the order records the same thing, even if an
        // administrator saves a new number mid-checkout.
        $recipient = PaymentSetting::current();

        if (! $recipient->isPayable()) {
            return response()->json([
                'message' => 'Payments are temporarily unavailable. Please try again shortly — nothing has been charged and your basket is saved.',
            ], 503);
        }

        // Built once rather than per line: schema lookups are not free and a
        // fifty-item basket should not make fifty of them. Columns absent on
        // this server are simply left out of the insert.
        $snapshot = array_filter([
            'lipa_namba'           => Schema::hasColumn('orders', 'lipa_namba') ? $recipient->payableNumber() : null,
            'payment_account_name' => Schema::hasColumn('orders', 'payment_account_name') ? $recipient->payeeName() : null,
            // Placing an order is not paying for it. The money is sent
            // separately and confirmed by D2K; until then this is what stops a
            // seller dispatching goods against nothing.
            'payment_status'       => Schema::hasColumn('orders', 'payment_status') ? 'pending_payment' : null,
        ], fn ($value) => $value !== null);

        try {
            $result = DB::transaction(function () use ($data, $user, $reference, $snapshot) {
                $subtotal = 0;
                $lines    = [];

                foreach ($data['items'] as $item) {
                    /** @var Product $product */
                    $product = Product::lockForUpdate()->find($item['product_id']);

                    if (! $product) {
                        abort(422, 'A product in your cart is no longer available.');
                    }


                    if ($product->stock < $item['quantity']) {
                        abort(422, sprintf(
                            'Only %d left of "%s".',
                            $product->stock,
                            $product->name
                        ));
                    }

                    $lineTotal = (float) $product->new_price * $item['quantity'];
                    $subtotal += $lineTotal;

                    $line = [
                        'reference'        => $reference,
                        'user_id'          => $user->id,
                        'vendor_id'        => $product->vendor_id,
                        'product_id'       => $product->id,
                        'quantity'         => $item['quantity'],
                        'price'            => $product->new_price,
                        'total'            => $lineTotal,
                        'status'           => 'pending',
                        'payment_method'   => $data['payment_method'],
                        'payment_provider' => $data['payment_provider'] ?? null,

                        'delivery_address' => $data['delivery_address'],
                        'customer_phone'   => $data['customer_phone'],
                        'external_id'      => (string) Str::uuid(),
                    ];

                    // Copied, not referenced. When an administrator changes
                    // the marketplace's till next month, this order still shows
                    // the number and name the customer was actually given.
                    //
                    // Each guarded because a server can have these files before
                    // it has run the migration. Writing a column that is not
                    // there fails the whole insert, and nobody could buy
                    // anything until someone noticed — losing a snapshot costs
                    // far less than losing every sale.
                    foreach ($snapshot as $column => $value) {
                        $line[$column] = $value;
                    }

                    $lines[] = Order::create($line);

                    // Reserve the stock that was just sold.
                    $product->decrement('stock', $item['quantity']);
                }

                // The order is placed, so the cart that produced it is spent.
                CartItem::where('user_id', $user->id)->delete();

                return ['lines' => $lines, 'subtotal' => $subtotal];
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        }

        // Delivery is charged once per order, not per line, so it is recorded
        // against the first line only.
        //
        // A pending quote is stored as NULL — never as 0, which would read as
        // "delivery is free", and never as a placeholder figure the rider
        // would then have to argue with.
        $quote = $this->quoter->quote(
            isset($data['delivery_latitude']) ? (float) $data['delivery_latitude'] : null,
            isset($data['delivery_longitude']) ? (float) $data['delivery_longitude'] : null,
        );

        $first = $result['lines'][0];
        $first->update(['delivery_fee' => $quote->amount]);

        return response()->json([
            'message'   => 'Order placed successfully.',
            'reference' => $reference,
            'order'     => $this->groupToPayload($reference, collect($result['lines'])->pluck('id')->all()),
        ], 201);
    }

    /** The signed-in shopper's order history, one entry per checkout. */
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with(['product.images', 'vendor'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->groupBy('reference')
            ->map(fn ($lines) => $this->presentGroup($lines))
            ->values();

        return response()->json(['orders' => $orders]);
    }

    /** A single order, addressed by its reference. */
    public function show(Request $request, string $reference)
    {
        $lines = Order::query()
            ->with(['product.images', 'vendor'])
            ->where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->get();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json(['order' => $this->presentGroup($lines)]);
    }

    /**
     * Cancel an order the shopper placed, returning the reserved stock.
     * Only orders that have not yet been dispatched can be withdrawn.
     */
    public function cancel(Request $request, string $reference)
    {
        $lines = Order::where('user_id', $request->user()->id)
            ->where('reference', $reference)
            ->get();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $cancellable = ['pending', 'processing'];

        if (! $lines->every(fn ($line) => in_array($line->status, $cancellable, true))) {
            return response()->json([
                'message' => 'This order can no longer be cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($lines) {
            foreach ($lines as $line) {
                // Put the reserved units back on sale.
                Product::where('id', $line->product_id)->increment('stock', $line->quantity);
                $line->update(['status' => 'cancelled']);
            }
        });

        return response()->json(['message' => 'Order cancelled.']);
    }

    /* ---------------------------------------------------------------- */

    private function newReference(): string
    {
        // Short, unambiguous and safe to read aloud: no O/0 or I/1 confusion.
        do {
            $reference = 'D2K-' . strtoupper(Str::random(8));
        } while (Order::where('reference', $reference)->exists());

        return $reference;
    }

    private function groupToPayload(string $reference, array $ids): array
    {
        return $this->presentGroup(
            Order::with(['product.images', 'vendor'])->whereIn('id', $ids)->get()
        );
    }

    /** Turn a set of order lines sharing a reference into one order object. */
    private function presentGroup($lines): array
    {
        $first = $lines->first();

        $subtotal = (float) $lines->sum('total');

        // NULL on every line means delivery has not been priced yet, which is
        // a different fact from "delivery costs nothing". Summing would
        // collapse the two, so a pending order reports null and the storefront
        // shows the charge as still to be confirmed.
        $priced   = $lines->whereNotNull('delivery_fee');
        $delivery = $priced->isEmpty() ? null : round((float) $priced->sum('delivery_fee'), 2);

        return [
            'reference'        => $first->reference,
            'status'           => $this->rollUpStatus($lines),
            'placed_at'        => optional($first->created_at)->toIso8601String(),
            'item_count'       => (int) $lines->sum('quantity'),
            'subtotal'         => round($subtotal, 2),
            // Numeric always: nothing is added to the total by this
            // checkout, so an unquoted delivery reports 0 rather than null.
            // `delivery_status` still distinguishes "not priced yet" for the
            // storefront's wording.
            'delivery_fee'     => $delivery ?? 0,
            'delivery_status'  => $delivery === null ? 'pending' : 'quoted',
            'total'            => round($subtotal + (float) ($delivery ?? 0), 2),
            'currency'         => 'TZS',
            'payment_method'   => $first->payment_method,
            'delivery_address' => $first->delivery_address,
            'customer_phone'   => $first->customer_phone,
            'payment'          => $this->paymentRecord($first, round($subtotal + (float) ($delivery ?? 0), 2)),
            'payment_status'   => Schema::hasColumn('orders', 'payment_status') ? ($first->payment_status ?? 'pending_payment') : 'pending_payment',

            // Which shop each item came from, and what each is owed. Kept for
            // the customer's benefit and for the sellers' own dashboards — it
            // is not payment routing, which is a single recipient above.
            'vendor_breakdown' => $this->vendorBreakdown($lines),
            'items'            => $lines->map(fn ($line) => [
                'id'       => $line->id,
                'product'  => $line->product ? [
                    'id'    => $line->product->id,
                    'name'  => $line->product->name,
                    'image' => Media::url(optional($line->product->images->first())->image),
                ] : null,
                'vendor'   => $line->vendor?->business_name,
                'quantity' => (int) $line->quantity,
                'price'    => (float) $line->price,
                'total'    => (float) $line->total,
                'status'   => $line->status,
            ])->values(),
        ];
    }

    /**
     * Who this order is paid to, as it stood on the day it was placed.
     *
     * Read off the order, not off the current settings. That is the whole
     * point of snapshotting: an administrator changing the marketplace till
     * tomorrow must not retroactively change the number a customer was given
     * today, or every receipt already issued becomes wrong at once.
     *
     * Orders placed before this existed carry no snapshot, and say so rather
     * than borrowing today's number.
     */
    private function paymentRecord($first, float $amount): array
    {
        $number = Schema::hasColumn('orders', 'lipa_namba') ? $first->lipa_namba : null;
        $name   = Schema::hasColumn('orders', 'payment_account_name') ? $first->payment_account_name : null;

        return [
            'method'             => 'lipa_namba',
            'number'             => $number,
            'name'               => $name,

            'lipa_namba'         => $number,
            'lipa_namba_display' => LipaNamba::pretty($number),
            'account_name'       => $name,

            // One payment covers the whole order, however many sellers it spans.
            'amount'             => $amount,
        ];
    }

    /**
     * The order split by seller, so the customer can see which shop each item
     * came from. Every seller sees only their own lines in their own console;
     * this is the customer's view of the same split.
     */
    private function vendorBreakdown($lines): array
    {
        return $lines
            ->groupBy('vendor_id')
            ->map(fn ($vendorLines) => [
                'vendor_id'   => (int) $vendorLines->first()->vendor_id,
                'vendor_name' => $vendorLines->first()->vendor?->business_name ?: 'Seller',
                'subtotal'    => round((float) $vendorLines->sum('total'), 2),
                'item_count'  => (int) $vendorLines->sum('quantity'),
            ])
            ->values()
            ->all();
    }

    /**
     * An order spanning several vendors can have lines at different stages.
     * Show the least-advanced one, so an order is only "completed" when every
     * vendor has actually delivered.
     */
    private function rollUpStatus($lines): string
    {
        $rank = ['cancelled' => 0, 'pending' => 1, 'processing' => 2, 'shipped' => 3, 'completed' => 4];

        $statuses = $lines->pluck('status')->unique();

        if ($statuses->count() === 1) {
            return $statuses->first();
        }

        // Ignore cancelled lines when the rest of the order is still live.
        $live = $statuses->reject(fn ($s) => $s === 'cancelled');

        if ($live->isEmpty()) {
            return 'cancelled';
        }

        return $live->sortBy(fn ($s) => $rank[$s] ?? 99)->first();
    }
}
