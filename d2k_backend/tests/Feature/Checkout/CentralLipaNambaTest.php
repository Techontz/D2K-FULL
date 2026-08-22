<?php

namespace Tests\Feature\Checkout;

use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One basket, one payment, one recipient.
 *
 * The rule these tests exist to hold: however many shops a customer buys from,
 * they are shown a single Lipa Namba and send a single amount to
 * Direct2Kariakoo. Handing them one number per seller meant three transfers
 * for a three-shop basket, which no customer makes and which cannot be
 * reconciled against one order.
 */
class CentralLipaNambaTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendorA;
    private Vendor $vendorB;
    private Vendor $vendorC;
    private Product $productA;
    private Product $productB;
    private Product $productC;
    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentSetting::create([
            'lipa_namba'   => '552354',
            'account_name' => 'Direct2Kariakoo',
            'is_active'    => true,
        ]);

        $category    = Category::create(['name' => 'Electronics']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Phones']);

        $this->vendorA = $this->makeVendor('Vendor A', 'a@test.local');
        $this->vendorB = $this->makeVendor('Vendor B', 'b@test.local');
        $this->vendorC = $this->makeVendor('Vendor C', 'c@test.local');

        $this->productA = $this->makeProduct($this->vendorA, $category, $subcategory, 'Product A', 20000);
        $this->productB = $this->makeProduct($this->vendorB, $category, $subcategory, 'Product B', 30000);
        $this->productC = $this->makeProduct($this->vendorC, $category, $subcategory, 'Product C', 15000);

        $this->shopper = User::create([
            'name' => 'Shopper', 'email' => 'shopper@test.local',
            'password' => bcrypt('secret123'), 'role' => 'user', 'phone' => '0700000009',
        ]);
    }

    /* ---------------- TEST A: one vendor ---------------- */

    public function test_a_single_vendor_basket_shows_the_central_number(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA])
            ->assertOk()
            ->assertJsonPath('payment.lipa_namba', '552354')
            ->assertJsonPath('payment.account_name', 'Direct2Kariakoo')
            ->assertJsonPath('payment.amount', 20000)
            ->assertJsonPath('can_place_order', true);
    }

    /* ---------------- TEST B: two vendors ---------------- */

    public function test_two_vendors_are_one_payment_of_one_amount(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->preview([$this->productA, $this->productB])->assertOk();

        // One recipient, not one per shop.
        $this->assertSame('552354', $response->json('payment.lipa_namba'));
        $this->assertEquals(50000, $response->json('payment.amount'));
        $this->assertEquals(50000, $response->json('subtotal'));
        $this->assertTrue($response->json('can_place_order'));

        // The basket is still split by shop for the customer to read, but that
        // split carries no payment details at all.
        $breakdown = collect($response->json('vendor_groups'))->keyBy('vendor_name');
        $this->assertCount(2, $breakdown);
        $this->assertEquals(20000, $breakdown['Vendor A']['subtotal']);
        $this->assertEquals(30000, $breakdown['Vendor B']['subtotal']);
        $this->assertArrayNotHasKey('lipa_namba', $breakdown['Vendor A']);
    }

    /* ---------------- TEST C: three vendors, one order ---------------- */

    public function test_three_vendors_make_one_order_and_each_sees_only_their_own(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->preview([$this->productA, $this->productB, $this->productC])->assertOk();
        $this->assertEquals(65000, $response->json('payment.amount'));
        $this->assertSame('552354', $response->json('payment.lipa_namba'));

        $reference = $this->placeOrder([$this->productA, $this->productB, $this->productC])
            ->assertCreated()->json('reference');

        // One order, one reference, one payment.
        $order = $this->getJson("/api/shop/orders/{$reference}")->assertOk()->json('order');
        $this->assertEquals(65000, $order['total']);
        $this->assertSame('552354', $order['payment']['lipa_namba']);
        $this->assertEquals(65000, $order['payment']['amount']);
        $this->assertCount(3, $order['vendor_breakdown']);

        // Each seller sees only their own line. This is the separation the
        // brief is most insistent about not breaking.
        foreach ([[$this->vendorA, 20000], [$this->vendorB, 30000], [$this->vendorC, 15000]] as [$vendor, $expected]) {
            Sanctum::actingAs($vendor->user);

            $lines = $this->getJson('/api/shop/vendor/orders')->assertOk()->json('orders');

            $this->assertCount(1, $lines, "{$vendor->business_name} should see exactly their own line.");
            $this->assertEquals($expected, $lines[0]['total']);
        }
    }

    /* ---------------- TEST D: admin changes the number ---------------- */

    public function test_changing_the_central_number_changes_new_checkouts(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA])->assertJsonPath('payment.lipa_namba', '552354');

        PaymentSetting::query()->first()->update(['lipa_namba' => '998877']);

        $this->preview([$this->productA])->assertJsonPath('payment.lipa_namba', '998877');
    }

    /* ---------------- TEST E: the snapshot ---------------- */

    public function test_an_old_order_keeps_the_number_it_was_placed_with(): void
    {
        Sanctum::actingAs($this->shopper);

        $first = $this->placeOrder([$this->productA])->assertCreated()->json('reference');

        PaymentSetting::query()->first()->update([
            'lipa_namba'   => '998877',
            'account_name' => 'D2K Tanzania Ltd',
        ]);

        $second = $this->placeOrder([$this->productB])->assertCreated()->json('reference');

        // The older order still names what its customer was actually given.
        $this->getJson("/api/shop/orders/{$first}")
            ->assertOk()
            ->assertJsonPath('order.payment.lipa_namba', '552354')
            ->assertJsonPath('order.payment.account_name', 'Direct2Kariakoo');

        $this->getJson("/api/shop/orders/{$second}")
            ->assertOk()
            ->assertJsonPath('order.payment.lipa_namba', '998877')
            ->assertJsonPath('order.payment.account_name', 'D2K Tanzania Ltd');
    }

    /* ---------------- payment safety ---------------- */

    public function test_placing_an_order_does_not_mark_it_paid(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->placeOrder([$this->productA])->assertCreated()->json('reference');

        $this->assertSame('pending_payment', Order::where('reference', $reference)->value('payment_status'));

        $this->getJson("/api/shop/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'pending_payment');
    }

    public function test_the_order_records_the_method_and_every_vendor(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->placeOrder([$this->productA, $this->productB])->assertCreated()->json('reference');

        $lines = Order::where('reference', $reference)->get();

        $this->assertCount(2, $lines);
        $this->assertTrue($lines->every(fn ($line) => $line->payment_method === 'lipa_namba'));
        $this->assertTrue($lines->every(fn ($line) => $line->lipa_namba === '552354'));
        $this->assertEqualsCanonicalizing(
            [$this->vendorA->id, $this->vendorB->id],
            $lines->pluck('vendor_id')->all(),
        );
    }

    /* ---------------- no seller can block a sale ---------------- */

    public function test_a_seller_with_no_payment_details_of_their_own_is_irrelevant(): void
    {
        // Nothing about a vendor is consulted for payment any more. This is the
        // regression that made checkout dead: one unconfigured shop used to
        // disable the whole basket.
        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA, $this->productB, $this->productC])
            ->assertOk()
            ->assertJsonPath('can_place_order', true);

        $this->placeOrder([$this->productA, $this->productB, $this->productC])->assertCreated();
    }

    /* ---------------- the marketplace's own till ---------------- */

    public function test_checkout_stops_when_the_marketplace_has_no_till(): void
    {
        PaymentSetting::query()->first()->update(['lipa_namba' => null]);
        config(['payment.lipa_namba' => null]);

        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA])
            ->assertOk()
            ->assertJsonPath('can_place_order', false)
            ->assertJsonPath('payment_unavailable', true)
            ->assertJsonPath('payment.lipa_namba', null);

        $this->placeOrder([$this->productA])->assertStatus(503);
        $this->assertSame(0, Order::count());
    }

    public function test_switching_payments_off_is_not_the_same_as_having_no_number(): void
    {
        PaymentSetting::query()->first()->update(['is_active' => false]);

        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA])->assertOk()->assertJsonPath('can_place_order', false);
    }

    public function test_settings_fall_back_to_config_when_the_table_is_empty(): void
    {
        PaymentSetting::query()->delete();
        config(['payment.lipa_namba' => '445566', 'payment.account_name' => 'D2K']);

        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA])
            ->assertOk()
            ->assertJsonPath('payment.lipa_namba', '445566')
            ->assertJsonPath('payment.account_name', 'D2K');
    }

    /* ---------------- the response contract ---------------- */

    public function test_the_checkout_response_carries_the_shape_the_api_promises(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->preview([$this->productA, $this->productB, $this->productC])
            ->assertOk()
            ->assertJsonPath('payment.method', 'lipa_namba')
            ->assertJsonPath('payment.number', '552354')
            ->assertJsonPath('payment.name', 'Direct2Kariakoo')
            ->assertJsonPath('subtotal', 65000)
            ->assertJsonPath('delivery_fee', 0)
            ->assertJsonPath('total', 65000);
    }

    public function test_no_vendor_payment_details_appear_anywhere_in_the_response(): void
    {
        Sanctum::actingAs($this->shopper);

        $body = $this->preview([$this->productA, $this->productB])->assertOk()->getContent();

        // The whole point: not one shop's number reaches the customer.
        $this->assertStringNotContainsString('payment_options', $body);
        $this->assertStringNotContainsString('vendor_lipa', $body);

        foreach ($this->preview([$this->productA, $this->productB])->json('vendor_groups') as $group) {
            $this->assertArrayNotHasKey('lipa_namba', $group);
            $this->assertArrayNotHasKey('payment_options', $group);
        }
    }

    public function test_the_legacy_vendor_preview_no_longer_returns_payment_options(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->postJson('/api/checkout/vendors', [
            'items' => [
                ['product_id' => $this->productA->id, 'quantity' => 1],
                ['product_id' => $this->productB->id, 'quantity' => 1],
            ],
        ])->assertOk();

        foreach ($response->json('vendors') as $entry) {
            $this->assertArrayNotHasKey('payment_options', $entry['vendor']);
            // Vendor grouping stays: it is how each shop is fulfilled.
            $this->assertArrayHasKey('id', $entry['vendor']);
        }
    }

    public function test_the_order_response_carries_the_same_shape(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->placeOrder([$this->productA, $this->productB])->assertCreated()->json('reference');

        $this->getJson("/api/shop/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('order.reference', $reference)
            ->assertJsonPath('order.payment.method', 'lipa_namba')
            ->assertJsonPath('order.payment.number', '552354')
            ->assertJsonPath('order.payment.name', 'Direct2Kariakoo')
            ->assertJsonPath('order.delivery_fee', 0)
            ->assertJsonPath('order.subtotal', 50000)
            ->assertJsonPath('order.total', 50000);
    }

    /* ---------------- stock ---------------- */

    public function test_stock_falls_exactly_once_per_item(): void
    {
        Sanctum::actingAs($this->shopper);

        $before = [
            $this->productA->stock,
            $this->productB->stock,
            $this->productC->stock,
        ];

        $this->placeOrder([$this->productA, $this->productB, $this->productC])->assertCreated();

        $this->assertSame($before[0] - 1, $this->productA->fresh()->stock);
        $this->assertSame($before[1] - 1, $this->productB->fresh()->stock);
        $this->assertSame($before[2] - 1, $this->productC->fresh()->stock);
    }

    public function test_every_line_of_a_multi_vendor_order_shares_one_reference(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->placeOrder([$this->productA, $this->productB, $this->productC])
            ->assertCreated()->json('reference');

        $this->assertMatchesRegularExpression('/^D2K-[A-Z0-9]{8}$/', $reference);
        $this->assertSame(3, Order::where('reference', $reference)->count());
        $this->assertSame(1, Order::query()->distinct()->count('reference'));
    }

    /* ---------------- pay on delivery, and the flat fee ---------------- */

    public function test_pay_on_delivery_is_refused(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->productA->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'cash_on_delivery',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_no_flat_delivery_fee_is_added(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->preview([$this->productA, $this->productB, $this->productC])->assertOk();

        $this->assertEquals(65000, $response->json('total'));
        $this->assertNull($response->json('delivery.amount'));
        $this->assertSame('pending', $response->json('delivery.status'));

        $reference = $this->placeOrder([$this->productA])->assertCreated()->json('reference');
        $this->assertNull(Order::where('reference', $reference)->value('delivery_fee'));
    }

    /* ---------------- helpers ---------------- */

    private function preview(array $products)
    {
        return $this->postJson('/api/shop/checkout/preview', [
            'items' => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1], $products),
        ]);
    }

    private function placeOrder(array $products)
    {
        return $this->postJson('/api/shop/orders', [
            'items'            => array_map(fn ($p) => ['product_id' => $p->id, 'quantity' => 1], $products),
            'delivery_address' => 'Mtaa wa Congo, Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ]);
    }

    private function makeVendor(string $name, string $email): Vendor
    {
        $owner = User::create([
            'name' => $name, 'email' => $email,
            'password' => bcrypt('secret123'), 'role' => 'vendor', 'phone' => '0700000001',
        ]);

        return Vendor::create([
            'user_id' => $owner->id, 'business_name' => $name,
            'phone' => '0700000001', 'is_approved' => true,
        ]);
    }

    private function makeProduct(Vendor $vendor, Category $category, Subcategory $subcategory, string $name, float $price): Product
    {
        return Product::create([
            'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'subcategory_id' => $subcategory->id, 'name' => $name,
            'description' => 'A test product', 'old_price' => $price * 1.2,
            'new_price' => $price, 'stock' => 10,
        ]);
    }
}
