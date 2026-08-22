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
 * Delivery is priced by distance, or honestly reported as not yet priced.
 *
 * What it is never again is a flat TZS 3,000 applied to every order in the
 * country, which either overcharged the customer round the corner or underpaid
 * the rider going to Mbagala.
 */
class DeliveryPricingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentSetting::create([
            'lipa_namba' => '552354', 'account_name' => 'Direct2Kariakoo', 'is_active' => true,
        ]);

        $owner = User::create([
            'name' => 'Seller', 'email' => 'seller@test.local',
            'password' => bcrypt('secret123'), 'role' => 'vendor', 'phone' => '0700000001',
        ]);
        $vendor = Vendor::create([
            'user_id' => $owner->id, 'business_name' => 'Kariakoo Store',
            'phone' => '0700000001', 'is_approved' => true,
        ]);

        $category = Category::create(['name' => 'Electronics']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Phones']);

        $this->product = Product::create([
            'vendor_id' => $vendor->id, 'category_id' => $category->id,
            'subcategory_id' => $subcategory->id, 'name' => 'Phone',
            'description' => 'A test handset', 'old_price' => 96000,
            'new_price' => 80000, 'stock' => 10,
        ]);

        $this->shopper = User::create([
            'name' => 'Shopper', 'email' => 'shopper@test.local',
            'password' => bcrypt('secret123'), 'role' => 'user', 'phone' => '0700000009',
        ]);
    }

    public function test_with_no_rate_card_the_fee_is_pending_and_nothing_is_added(): void
    {
        Sanctum::actingAs($this->shopper);

        $this->postJson('/api/shop/checkout/preview', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('subtotal', 160000)
            ->assertJsonPath('total', 160000)
            ->assertJsonPath('delivery.amount', null)
            ->assertJsonPath('delivery.status', 'pending');
    }

    public function test_an_order_records_a_pending_fee_as_null_not_zero(): void
    {
        Sanctum::actingAs($this->shopper);

        $reference = $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->product->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertCreated()->json('reference');

        // NULL, not 0 and not 3000 — "nobody has priced this yet" is a
        // different fact from "delivery is free".
        $this->assertNull(Order::where('reference', $reference)->value('delivery_fee'));

        $this->getJson("/api/shop/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('order.delivery_status', 'pending')
            ->assertJsonPath('order.total', 80000);
    }

    public function test_distance_changes_the_price_once_a_rate_card_exists(): void
    {
        config([
            'delivery.origin.latitude'  => -6.8161,   // Kariakoo market
            'delivery.origin.longitude' => 39.2803,
            'delivery.base_fee'         => 1000,
            'delivery.per_km'           => 500,
            'delivery.minimum_fee'      => 1500,
        ]);

        Sanctum::actingAs($this->shopper);

        $near = $this->postJson('/api/shop/checkout/preview', [
            'items'              => [['product_id' => $this->product->id, 'quantity' => 1]],
            'delivery_latitude'  => -6.8200,
            'delivery_longitude' => 39.2850,
        ])->assertOk();

        $far = $this->postJson('/api/shop/checkout/preview', [
            'items'              => [['product_id' => $this->product->id, 'quantity' => 1]],
            'delivery_latitude'  => -6.9500,   // Mbagala
            'delivery_longitude' => 39.2600,
        ])->assertOk();

        $this->assertSame('quoted', $near->json('delivery.status'));
        $this->assertGreaterThan(
            $near->json('delivery.amount'),
            $far->json('delivery.amount'),
            'A delivery twice as far must not cost the same as one round the corner.',
        );

        // The total, and the amount the customer is asked to send, both include it.
        $this->assertEquals(round(80000 + $far->json('delivery.amount'), 2), $far->json('total'));
        $this->assertEquals($far->json('total'), $far->json('payment.amount'));
    }

    public function test_a_quoted_fee_is_saved_on_the_order(): void
    {
        config([
            'delivery.origin.latitude'  => -6.8161,
            'delivery.origin.longitude' => 39.2803,
            'delivery.base_fee'         => 1000,
            'delivery.per_km'           => 500,
        ]);

        Sanctum::actingAs($this->shopper);

        $reference = $this->postJson('/api/shop/orders', [
            'items'              => [['product_id' => $this->product->id, 'quantity' => 1]],
            'delivery_address'   => 'Mbagala',
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
}
