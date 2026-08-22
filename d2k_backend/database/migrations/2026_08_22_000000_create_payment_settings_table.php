<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marketplace's own payment details, editable by an administrator.
 *
 * One row, not one per vendor. A customer buying from three sellers makes a
 * single payment to D2K, which then settles with each seller — asking them to
 * make three separate transfers to three different tills was both worse for
 * the customer and impossible to reconcile against one order.
 *
 * A table rather than a config file because an administrator has to be able to
 * change the till without a developer and a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_settings')) {
            return;
        }

        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();

            // Nullable: the row can exist before anyone has filled it in, and
            // checkout says "payment is not configured" rather than showing a
            // blank number the customer would send money into.
            $table->string('lipa_namba', 32)->nullable();
            $table->string('account_name')->nullable();

            // Lets payment be switched off deliberately, which is a different
            // state from "nobody has set it up".
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Drops only the table this migration created. The orders that quote its
     * values keep their own snapshot, so no payment record is lost.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
