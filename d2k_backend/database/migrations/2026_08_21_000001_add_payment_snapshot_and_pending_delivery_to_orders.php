<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes to `orders`, both additive in effect.
 *
 * 1. `lipa_namba` — the number the customer was actually shown when they
 *    placed the order, copied off the vendor at that moment. A seller who
 *    changes their till number must not silently rewrite what every past
 *    customer was told to pay into, and a support call about a three-month-old
 *    order has to be answerable from the order row alone.
 *
 *    It lives on the line rather than on the checkout because one reference
 *    can span several vendors, and each of those vendors has their own number.
 *
 * 2. `delivery_fee` becomes nullable. It was `NOT NULL DEFAULT 0`, which gave
 *    "delivery is free" and "nobody has worked out the delivery cost yet" the
 *    same representation. Now that the flat TZS 3,000 charge is gone and the
 *    real figure depends on distance, those two need to be distinguishable —
 *    NULL means awaiting calculation.
 *
 * No existing row is read or rewritten: current values (0 or 3000) survive
 * exactly as they are, and every historic order stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'lipa_namba')) {
                $table->string('lipa_namba', 32)->nullable()->after('payment_method');
            }
        });

        if (Schema::hasColumn('orders', 'delivery_fee')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('delivery_fee', 12, 2)->nullable()->default(null)->change();
            });
        }
    }

    /**
     * Only the new column is dropped. `delivery_fee` is deliberately left
     * nullable: forcing it back to NOT NULL would have to invent a value for
     * every order placed while the fee was still pending.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'lipa_namba')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('lipa_namba');
        });
    }
};
