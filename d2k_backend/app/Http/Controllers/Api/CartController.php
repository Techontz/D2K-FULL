<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\ProductOptions;

/**
 * The server-side cart.
 *
 * A line is one product in one choice of options: the same shirt in M and in
 * L is two lines, the same shirt in M added twice is one line with quantity 2.
 * Every line carries `options` ({"Size": "M"} or null). Update and remove
 * address a line by its cart item `id`; the older product_id addressing still
 * works for clients that predate options.
 */
class CartController extends Controller
{
    /**
     * 🛒 Get all cart items for the authenticated user
     */
    public function index(Request $request)
    {
        return response()->json(['items' => $this->lines($request)]);
    }

    /**
     * ➕ Add to cart (or increment quantity)
     */
    public function add(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'sometimes|integer|min:1',
            'options'    => 'sometimes|nullable',
        ]);

        $product = Product::findOrFail($request->input('product_id'));
        $qty     = (int) $request->input('quantity', 1);

        // Rejects a missing, unknown or unavailable choice with a 422 keyed
        // on the option, e.g. errors["options.Size"].
        $options = ProductOptions::validate($product, $request->input('options'));

        if (ProductOptions::cartSupportsOptions()) {
            $cartItem = CartItem::firstOrNew([
                'user_id'     => $user->id,
                'product_id'  => $product->id,
                'options_key' => ProductOptions::key($options),
            ]);
            $cartItem->options = $options;
        } else {
            $cartItem = CartItem::firstOrNew([
                'user_id'    => $user->id,
                'product_id' => $product->id,
            ]);
        }

        $cartItem->quantity = ($cartItem->exists ? $cartItem->quantity : 0) + $qty;
        $cartItem->save();

        return response()->json([
            'message' => 'Added to cart successfully.',
            'item'    => $cartItem->fresh()->load('product'),
        ]);
    }

    /**
     * ➖ Update quantity (increment/decrement)
     */
    public function update(Request $request)
    {
        $request->validate([
            'id'         => 'required_without:product_id|integer',
            'product_id' => 'required_without:id|integer|exists:products,id',
            'quantity'   => 'required|integer|min:0',
            'options'    => 'sometimes|nullable',
        ]);

        $cartItem = $this->findLine($request);

        if (!$cartItem) {
            return response()->json(['message' => 'Item not found in cart'], 404);
        }

        // ✅ If quantity is 0 → remove item
        if ($request->quantity == 0) {
            $cartItem->delete();

            return response()->json([
                'message' => 'Item removed from cart',
                'cart'    => $this->lines($request),
            ]);
        }

        // ✅ Otherwise update the quantity. Options are left as they are: a
        // different choice is a different line, added through add().
        $cartItem->update(['quantity' => $request->quantity]);

        return response()->json([
            'message' => 'Cart updated successfully',
            'cart'    => $this->lines($request),
        ]);
    }

    /**
     * 🗑️ Remove item completely
     */
    public function remove(Request $request)
    {
        $request->validate([
            'id'         => 'required_without:product_id|integer',
            'product_id' => 'required_without:id|exists:products,id',
            'options'    => 'sometimes|nullable',
        ]);

        $query = CartItem::where('user_id', $request->user()->id);

        if ($request->filled('id')) {
            $query->where('id', $request->integer('id'));
        } else {
            $query->where('product_id', $request->product_id);

            // Without options this removes every line of the product, as it
            // always did; with them, only the matching choice.
            if ($request->has('options') && ProductOptions::cartSupportsOptions()) {
                $query->where('options_key', $this->keyFor($request));
            }
        }

        $deleted = $query->delete();

        return response()->json([
            'message' => $deleted ? 'Removed from cart.' : 'Item not found.',
        ]);
    }

    /**
     * 🧹 Clear the entire cart
     */
    public function clear(Request $request)
    {
        $user = $request->user();

        CartItem::where('user_id', $user->id)->delete();

        return response()->json([
            'message' => 'Cart cleared successfully.',
        ]);
    }

    /* ---------------------------------------------------------------- */

    private function lines(Request $request)
    {
        return CartItem::with('product')
            ->where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get();
    }

    /** The caller's line, by cart item id, or by product (+ options). */
    private function findLine(Request $request): ?CartItem
    {
        $query = CartItem::where('user_id', $request->user()->id);

        if ($request->filled('id')) {
            return $query->where('id', $request->integer('id'))->first();
        }

        $query->where('product_id', $request->product_id);

        if ($request->has('options') && ProductOptions::cartSupportsOptions()) {
            $query->where('options_key', $this->keyFor($request));
        }

        return $query->orderBy('id')->first();
    }

    /**
     * Fingerprint of the options a client used to identify a line. Not
     * validated against the product: an unknown choice simply matches nothing.
     */
    private function keyFor(Request $request): string
    {
        $options = ProductOptions::present($request->input('options'));

        return ProductOptions::key(
            $options === null ? null : array_map(fn ($v) => trim((string) $v), $options)
        );
    }
}
