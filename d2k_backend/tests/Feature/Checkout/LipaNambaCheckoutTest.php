<?php

namespace Tests\Feature\Checkout;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Support\LipaNamba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paying the right seller, and not being charged for delivery nobody has
 * priced yet.
 *
 * Two rules are load-bearing here and both are about money going astray:
 * a customer must see the till number of the store they are actually buying
 * from, and the number printed on an order must stay the number that was
 * printed on it, however often the seller edits their profile afterwards.
 */
class LipaNambaCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendorA;
    private Vendor $vendorB;
    private Vendor $vendorNoTill;
    private Product $productA;
    private Product $productB;
    private Product $productNoTill;
    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        $category    = Category::create(['name' => 'Electronics']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Phones']);

        $this->vendorA     = $this->makeVendor('Vendor A', 'a@test.local', '111222');
        $this->vendorB     = $this->makeVendor('Vendor B', 'b@test.local', '999888');
        $this->vendorNoTill = $this->makeVendor('Vendor C', 'c@test.local', null);

        $this->productA = $this->makeProduct($this->vendorA, $category, $subcategory, 'Phone A', 80000);
        $this->productB = $this->makeProduct($this->vendorB, $category, $subcategory, 'Phone B', 50000);
        $this->productNoTill = $this->makeProduct($this->vendorNoTill, $category, $subcategory, 'Phone C', 20000);

        $this->shopper = User::create([
            'name' => 'Shopper', 'email' => 'shopper@test.local',
            'password' => bcrypt('secret123'), 'role' => 'user', 'phone' => '0700000009',
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* A–D: each vendor's own number, never anybody else's               */
    /* ---------------------------------------------------------------- */

    public function test_checkout_shows_the_lipa_namba_of_the_vendor_being_bought_from(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->previewFor($this->productA)
            ->assertOk()
            ->assertJsonPath('vendor_groups.0.vendor_name', 'Vendor A')
            ->assertJsonPath('vendor_groups.0.lipa_namba', '111222')
            ->assertJsonPath('vendor_groups.0.can_accept_orders', true);

        $this->previewFor($this->productB)
            ->assertOk()
            ->assertJsonPath('vendor_groups.0.vendor_name', 'Vendor B')
            ->assertJsonPath('vendor_groups.0.lipa_namba', '999888');
    }

    public function test_a_basket_from_two_vendors_keeps_their_numbers_apart(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->postJson('/api/shop/checkout/preview', [
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1],
                ['product_id' => $this->productB->id, 'quantity' => 2],
            ],
        ])->assertOk();

        $groups = collect($response->json('vendor_groups'))->keyBy('vendor_name');

        $this->assertCount(2, $groups);
        $this->assertSame('111222', $groups['Vendor A']['lipa_namba']);
        $this->assertSame('999888', $groups['Vendor B']['lipa_namba']);

        // Each seller is owed only their own line, not the basket total.
        // assertEquals, not assertSame: a whole number round-trips through
        // JSON as an int, and the type is not what is under test here.
        $this->assertEquals(80000, $groups['Vendor A']['subtotal']);
        $this->assertEquals(100000, $groups['Vendor B']['subtotal']);
    }

    /* ---------------------------------------------------------------- */
    /* F: a store with no till number                                    */
    /* ---------------------------------------------------------------- */

    public function test_a_vendor_without_a_lipa_namba_shows_no_number_and_blocks_checkout(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->previewFor($this->productNoTill)->assertOk();

        // Not blank, not somebody else's — explicitly absent, with a reason.
        $this->assertNull($response->json('vendor_groups.0.lipa_namba'));
        $this->assertFalse($response->json('vendor_groups.0.can_accept_orders'));
        $this->assertStringContainsString('Lipa Namba', $response->json('vendor_groups.0.blocked_reason'));
        $this->assertFalse($response->json('can_place_order'));
    }

    public function test_an_order_cannot_be_placed_for_a_vendor_without_a_lipa_namba(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productNoTill->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertStatus(422);

        $this->assertSame(0, Order::count(), 'A blocked checkout must not create order lines.');
        $this->assertSame(5, $this->productNoTill->fresh()->stock, 'A blocked checkout must not touch stock.');
    }

    public function test_one_seller_without_a_till_does_not_block_the_rest_of_the_basket(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->postJson('/api/shop/checkout/preview', [
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1],      // payable
                ['product_id' => $this->productNoTill->id, 'quantity' => 1], // not
            ],
        ])->assertOk();

        // The whole checkout used to go dead here because one store had not
        // configured a till. The payable seller is still orderable.
        $this->assertTrue($response->json('can_place_order'));

        $this->assertSame([$this->productA->id], $response->json('payable_product_ids'));
        $this->assertEquals(80000, $response->json('payable_subtotal'));

        // The unpayable store is still reported, so the shopper is told rather
        // than having their item silently vanish.
        $groups = collect($response->json('vendor_groups'))->keyBy('vendor_name');
        $this->assertFalse($groups['Vendor C']['can_accept_orders']);
        $this->assertNull($groups['Vendor C']['lipa_namba']);
    }

    public function test_the_payable_half_of_a_mixed_basket_can_actually_be_ordered(): void
    {
        Sanctum::actingAs($this->shopper);

        // The storefront submits only what the preview called payable.
        $reference = $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertCreated()->json('reference');

        $order = $this->getJson("/api/shop/orders/{$reference}")->assertOk()->json('order');

        $this->assertSame('lipa_namba', $order['payment_method']);
        $this->assertSame('111222', $order['vendor_payments'][0]['lipa_namba']);

        // The unpayable seller's stock was never touched.
        $this->assertSame(5, $this->productNoTill->fresh()->stock);
    }

    public function test_a_basket_of_only_unpayable_sellers_cannot_be_ordered(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->previewFor($this->productNoTill)->assertOk();

        $this->assertFalse($response->json('can_place_order'));
        $this->assertSame([], $response->json('payable_product_ids'));
    }

    /* ---------------------------------------------------------------- */
    /* E: the snapshot                                                   */
    /* ---------------------------------------------------------------- */

    public function test_the_order_keeps_the_number_it_was_placed_with(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertCreated()->json('reference');

        $this->assertSame('111222', Order::where('reference', $reference)->value('lipa_namba'));

        // Vendor A moves to a new till.
        $this->vendorA->update(['lipa_namba' => '555444']);

        // The historic order still names the number the customer was given.
        $this->getJson("/api/shop/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('order.vendor_payments.0.lipa_namba', '111222')
            ->assertJsonPath('order.vendor_payments.0.vendor_name', 'Vendor A');

        // A new order picks up the new number.
        $fresh = $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertCreated()->json('reference');

        $this->getJson("/api/shop/orders/{$fresh}")
            ->assertOk()
            ->assertJsonPath('order.vendor_payments.0.lipa_namba', '555444');
    }

    /* ---------------------------------------------------------------- */
    /* G: pay on delivery is gone                                        */
    /* ---------------------------------------------------------------- */

    public function test_pay_on_delivery_is_no_longer_accepted(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'cash_on_delivery',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Order::count());
    }

    /* ---------------------------------------------------------------- */
    /* H, I, J: delivery pricing and the total                           */
    /* ---------------------------------------------------------------- */

    public function test_no_flat_three_thousand_is_added_to_a_basket(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->previewFor($this->productA, 2)->assertOk();

        $this->assertEquals(160000, $response->json('subtotal'));
        $this->assertEquals(160000, $response->json('total'), 'Nothing may be added to the goods total.');
        $this->assertNull($response->json('delivery.amount'));
        $this->assertSame('pending', $response->json('delivery.status'));
    }

    public function test_an_order_placed_without_a_rate_card_records_delivery_as_pending(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertCreated()->json('reference');

        // NULL, not 0 and not 3000 — "nobody has priced this yet".
        $this->assertNull(Order::where('reference', $reference)->value('delivery_fee'));

        $this->getJson("/api/shop/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('order.delivery_status', 'pending')
            ->assertJsonPath('order.delivery_fee', null)
            ->assertJsonPath('order.subtotal', 80000)
            ->assertJsonPath('order.total', 80000);
    }

    public function test_delivery_is_priced_by_distance_once_a_rate_card_exists(): void
    {
        // Kariakoo market as the dispatch point, TZS 1,000 plus 500 a kilometre.
        config([
            'delivery.origin.latitude'  => -6.8161,
            'delivery.origin.longitude' => 39.2803,
            'delivery.base_fee'         => 1000,
            'delivery.per_km'           => 500,
            'delivery.minimum_fee'      => 1500,
        ]);

        Sanctum::actingAs($this->shopper);

        $near = $this->postJson('/api/shop/checkout/preview', [
            'items'              => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_latitude'  => -6.8200,
            'delivery_longitude' => 39.2850,
        ])->assertOk();

        $far = $this->postJson('/api/shop/checkout/preview', [
            'items'              => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_latitude'  => -6.9500,   // Mbagala, well to the south
            'delivery_longitude' => 39.2600,
        ])->assertOk();

        $nearFee = $near->json('delivery.amount');
        $farFee  = $far->json('delivery.amount');

        $this->assertSame('quoted', $near->json('delivery.status'));
        $this->assertNotNull($nearFee);
        $this->assertGreaterThan(
            $nearFee,
            $farFee,
            'A delivery twice as far must not cost the same as one round the corner.',
        );

        // J — the total is the goods plus exactly the quoted delivery.
        $this->assertEquals(
            round(80000 + $farFee, 2),
            $far->json('total'),
        );
    }

    public function test_a_quoted_delivery_fee_is_saved_on_the_order(): void
    {
        config([
            'delivery.origin.latitude'  => -6.8161,
            'delivery.origin.longitude' => 39.2803,
            'delivery.base_fee'         => 1000,
            'delivery.per_km'           => 500,
        ]);

        Sanctum::actingAs($this->shopper);

        $reference = $this->postJson('/api/shop/orders', [
            'items'              => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address'   => 'Mbagala, Dar es Salaam',
            'customer_phone'     => '0700000009',
            'payment_method'     => 'lipa_namba',
            'delivery_latitude'  => -6.9500,
            'delivery_longitude' => 39.2600,
        ])->assertCreated()->json('reference');

        $stored = (float) Order::where('reference', $reference)->value('delivery_fee');
        $this->assertGreaterThan(0, $stored);

        $order = $this->getJson("/api/shop/orders/{$reference}")->assertOk()->json('order');

        $this->assertSame('quoted', $order['delivery_status']);
        $this->assertEquals(round(80000 + $stored, 2), (float) $order['total']);
    }

    /* ---------------------------------------------------------------- */
    /* The seller's own control over the number                          */
    /* ---------------------------------------------------------------- */

    public function test_a_seller_can_set_and_change_their_lipa_namba(): void
    {
        Sanctum::actingAs($this->vendorA->user);

        $this->postJson('/api/shop/seller/store', ['lipa_namba' => '0764 224 477'])
            ->assertOk()
            // Stored normalised, so checkout and the console agree on the digits.
            ->assertJsonPath('store.lipa_namba', '0764224477')
            ->assertJsonPath('store.has_lipa_namba', true);

        $this->postJson('/api/shop/seller/store', ['lipa_namba' => '777666'])
            ->assertOk()
            ->assertJsonPath('store.lipa_namba', '777666');

        $this->assertSame('777666', $this->vendorA->fresh()->lipa_namba);
    }

    public function test_a_nonsense_lipa_namba_is_refused(): void
    {
        Sanctum::actingAs($this->vendorA->user);

        foreach (['abcdef', '12', '1234567890123456'] as $bad) {
            $this->postJson('/api/shop/seller/store', ['lipa_namba' => $bad])
                ->assertStatus(422);
        }

        $this->assertSame('111222', $this->vendorA->fresh()->lipa_namba, 'A rejected edit must not clear the old number.');
    }

    public function test_a_seller_can_take_their_number_down(): void
    {
        Sanctum::actingAs($this->vendorA->user);

        $this->postJson('/api/shop/seller/store', ['lipa_namba' => ''])
            ->assertOk()
            ->assertJsonPath('store.has_lipa_namba', false);

        $this->assertNull($this->vendorA->fresh()->lipa_namba);
    }

    /* ---------------------------------------------------------------- */
    /* Backward compatibility                                            */
    /* ---------------------------------------------------------------- */

    public function test_an_order_placed_before_lipa_namba_existed_is_still_readable(): void
    {
        // A historic row: the withdrawn payment method, the old flat fee, and
        // no snapshot, exactly as production holds it today.
        $legacy = Order::create([
            'reference'        => 'D2K-LEGACY01',
            'user_id'          => $this->shopper->id,
            'vendor_id'        => $this->vendorA->id,
            'product_id'       => $this->productA->id,
            'quantity'         => 1,
            'price'            => 80000,
            'total'            => 80000,
            'delivery_fee'     => 3000,
            'status'           => 'completed',
            'payment_method'   => 'cash_on_delivery',
            'delivery_address' => 'Kariakoo',
            'customer_phone'   => '0700000009',
        ]);

        Sanctum::actingAs($this->shopper);

        $this->getJson("/api/shop/orders/{$legacy->reference}")
            ->assertOk()
            ->assertJsonPath('order.payment_method', 'cash_on_delivery')
            ->assertJsonPath('order.delivery_fee', 3000)
            ->assertJsonPath('order.delivery_status', 'quoted')
            ->assertJsonPath('order.total', 83000)
            // No snapshot to show, so the number is absent rather than guessed
            // from the vendor's current profile.
            ->assertJsonPath('order.vendor_payments.0.lipa_namba', null);
    }

    /* ---------------------------------------------------------------- */

    public function test_the_lipa_namba_helper_normalises_what_sellers_type(): void
    {
        $this->assertSame('123456', LipaNamba::normalise(' 123 456 '));
        $this->assertSame('255764224477', LipaNamba::normalise('+255 764 224 477'));
        $this->assertSame('0764224477', LipaNamba::normalise('0764-224-477'));

        $this->assertNull(LipaNamba::normalise('12A456'));
        $this->assertNull(LipaNamba::normalise('1234'));
        $this->assertNull(LipaNamba::normalise('1234567890123'));
        $this->assertNull(LipaNamba::normalise(null));

        // Grouped from the right, so nothing is stranded at the end and no
        // group is left as a single digit.
        $this->assertSame('123456', LipaNamba::pretty('123456'));
        $this->assertSame('87 654 321', LipaNamba::pretty('87654321'));
        $this->assertSame('876 543 210', LipaNamba::pretty('876543210'));
        $this->assertSame('0764 224 477', LipaNamba::pretty('0764224477'));
        $this->assertSame('255 764 224 477', LipaNamba::pretty('255764224477'));
    }

    /* ---------------------------------------------------------------- */

    private function previewFor(Product $product, int $quantity = 1)
    {
        return $this->postJson('/api/shop/checkout/preview', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ]);
    }

    private function makeVendor(string $name, string $email, ?string $lipaNamba): Vendor
    {
        $owner = User::create([
            'name' => $name, 'email' => $email,
            'password' => bcrypt('secret123'), 'role' => 'vendor', 'phone' => '0700000001',
        ]);

        return Vendor::create([
            'user_id'     => $owner->id,
            'business_name' => $name,
            'phone'       => '0700000001',
            'is_approved' => true,
            'lipa_namba'  => $lipaNamba,
        ]);
    }

    private function makeProduct(Vendor $vendor, Category $category, Subcategory $subcategory, string $name, float $price): Product
    {
        return Product::create([
            'vendor_id'      => $vendor->id,
            'category_id'    => $category->id,
            'subcategory_id' => $subcategory->id,
            'name'           => $name,
            'description'    => 'A test handset',
            'old_price'      => $price * 1.2,
            'new_price'      => $price,
            'stock'          => 5,
        ]);
    }
}
