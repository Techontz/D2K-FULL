<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer was told to pay, and whether it has arrived.
 *
 * `payment_account_name` completes the snapshot alongside the `lipa_namba`
 * column: an order has to be able to say who the money was going to, in the
 * words the customer saw, months after an administrator changed the till.
 *
 * `payment_status` exists because placing an order is not paying for it. The
 * customer sends the money separately and somebody at D2K confirms it arrived;
 * until then the order is awaiting payment, and treating "ordered" as "paid"
 * would have sellers dispatching goods against nothing.
 *
 * Both are additive and nullable. No existing row is read or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_account_name')) {
                $table->string('payment_account_name')->nullable()->after('lipa_namba');
            }

            if (! Schema::hasColumn('orders', 'payment_status')) {
                // Historic orders are left NULL rather than being declared
                // unpaid: they predate this field and their real state is not
                // something this migration can know.
                $table->string('payment_status', 32)->nullable()->after('payment_account_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['payment_account_name', 'payment_status'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
