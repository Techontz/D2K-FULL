<?php

namespace Tests\Feature\Checkout;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\PaymentType;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The same feature on a database that has not had the new column added.
 *
 * This is not hypothetical: production has `vendor_payment_options` and no
 * `vendors.lipa_namba`, and the two halves of a deployment do not land at the
 * same moment. If the code only worked once the migration had run, checkout
 * would be dead in the window between the files going up and the SQL being
 * executed — which is exactly the failure this whole area keeps producing.
 *
 * Every test here drops the column first, so it exercises the older schema.
 */
class LipaNambaLegacySchemaTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;
    private Product $product;
    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand the database back down to what production actually has:
        // neither of the two columns the migration adds.
        Schema::table('vendors', function ($table) {
            $table->dropColumn('lipa_namba');
        });
        Schema::table('orders', function ($table) {
            $table->dropColumn('lipa_namba');
        });

        $owner = User::create([
            'name' => 'Legacy Store', 'email' => 'legacy@test.local',
            'password' => bcrypt('secret123'), 'role' => 'vendor', 'phone' => '0700000001',
        ]);

        $this->vendor = Vendor::create([
            'user_id' => $owner->id, 'business_name' => 'Legacy Store',
            'phone' => '0700000001', 'is_approved' => true,
        ]);

        $category = Category::create(['name' => 'Electronics']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Phones']);

        $this->product = Product::create([
            'vendor_id' => $this->vendor->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'name' => 'Legacy Phone', 'description' => 'A test handset',
            'old_price' => 96000, 'new_price' => 80000, 'stock' => 5,
        ]);

        $this->shopper = User::create([
            'name' => 'Shopper', 'email' => 'shopper@test.local',
            'password' => bcrypt('secret123'), 'role' => 'user', 'phone' => '0700000009',
        ]);
    }

    public function test_the_columns_really_are_absent(): void
    {
        $this->assertFalse(Schema::hasColumn('vendors', 'lipa_namba'));
        $this->assertFalse(
            Schema::hasColumn('orders', 'lipa_namba'),
            'This suite is meaningless if the columns are still there.',
        );
    }

    public function test_a_till_filed_under_the_old_payment_options_table_is_found(): void
    {
        $this->givePaymentOption('Lipa Namba', '55667788');

        $this->assertTrue($this->vendor->fresh()->hasLipaNamba());
        $this->assertSame('55667788', $this->vendor->fresh()->resolveLipaNamba());
    }

    public function test_checkout_shows_that_till_and_allows_the_order(): void
    {
        $this->givePaymentOption('Lipa Namba', '55667788');

        Sanctum::actingAs($this->shopper);

        $this->postJson('/api/shop/checkout/preview', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])
            ->assertOk()
            ->assertJsonPath('vendor_groups.0.lipa_namba', '55667788')
            ->assertJsonPath('vendor_groups.0.can_accept_orders', true)
            ->assertJsonPath('can_place_order', true);
    }

    public function test_an_order_can_be_placed_and_records_the_till(): void
    {
        $this->givePaymentOption('Lipa Namba', '55667788');

        Sanctum::actingAs($this->shopper);

        $reference = $this->postJson('/api/shop/orders', [
            'items'            => [['product_id' => $this->product->id, 'quantity' => 1]],
            'delivery_address' => 'Kariakoo, Dar es Salaam',
            'customer_phone'   => '0700000009',
            'payment_method'   => 'lipa_namba',
        ])->assertCreated()->json('reference');

        // The order is created and records the method. The snapshot column
        // does not exist here, so the number is not stored on the order — that
        // is what the migration buys, and its absence must not stop a sale.
        $this->getJson("/api/shop/orders/{$reference}")
            ->assertOk()
            ->assertJsonPath('order.payment_method', 'lipa_namba')
            ->assertJsonPath('order.vendor_payments.0.vendor_name', 'Legacy Store');

        $this->assertSame(4, $this->product->fresh()->stock);
    }

    public function test_a_seller_can_save_their_till_with_no_column_to_save_it_in(): void
    {
        Sanctum::actingAs($this->vendor->user);

        $this->postJson('/api/shop/seller/store', ['lipa_namba' => '4433 2211'])
            ->assertOk()
            ->assertJsonPath('store.lipa_namba', '44332211')
            ->assertJsonPath('store.has_lipa_namba', true);

        // It landed in the table this database actually has.
        $this->assertDatabaseHas('vendor_payment_options', [
            'vendor_id' => $this->vendor->id,
            'account'   => '44332211',
        ]);
    }

    public function test_the_saved_number_is_echoed_straight_back(): void
    {
        Sanctum::actingAs($this->vendor->user);

        // Regression: the response read a relation loaded before the write, so
        // it reported the number as empty and the console blanked the field the
        // seller had just filled in.
        $this->postJson('/api/shop/seller/store', ['lipa_namba' => '99887766'])
            ->assertOk()
            ->assertJsonPath('store.lipa_namba', '99887766')
            ->assertJsonPath('store.lipa_namba_display', '99 887 766')
            ->assertJsonPath('store.has_lipa_namba', true);
    }

    public function test_saving_twice_updates_rather_than_piling_up_rows(): void
    {
        Sanctum::actingAs($this->vendor->user);

        $this->postJson('/api/shop/seller/store', ['lipa_namba' => '11112222'])->assertOk();
        $this->postJson('/api/shop/seller/store', ['lipa_namba' => '33334444'])->assertOk();

        $this->assertSame(1, $this->vendor->paymentOptions()->count());
        $this->assertSame('33334444', $this->vendor->fresh()->resolveLipaNamba());
    }

    public function test_a_bank_account_filed_there_is_not_mistaken_for_a_till(): void
    {
        // A real risk: this table holds every kind of payment detail. Showing a
        // bank account as a Lipa Namba sends the customer somewhere they
        // cannot pay.
        $this->givePaymentOption('Bank Transfer', '01234567890');

        $this->assertFalse($this->vendor->fresh()->hasLipaNamba());
        $this->assertNull($this->vendor->fresh()->resolveLipaNamba());
    }

    public function test_a_seller_with_nothing_configured_is_reported_plainly(): void
    {
        Sanctum::actingAs($this->shopper);

        $response = $this->postJson('/api/shop/checkout/preview', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertNull($response->json('vendor_groups.0.lipa_namba'));
        $this->assertFalse($response->json('can_place_order'));
        $this->assertStringContainsString('Lipa Namba', $response->json('vendor_groups.0.blocked_reason'));
    }

    private function givePaymentOption(string $methodName, string $account): void
    {
        $type = PaymentType::create(['name' => 'Mobile Money']);
        $method = PaymentMethod::create(['payment_type_id' => $type->id, 'name' => $methodName]);

        $this->vendor->paymentOptions()->create([
            'payment_type_id'   => $type->id,
            'payment_method_id' => $method->id,
            'account'           => $account,
        ]);
    }
}
