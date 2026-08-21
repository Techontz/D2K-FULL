<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each seller's own Lipa Namba — the till number a customer pays into.
 *
 * It belongs on the vendor rather than in a settings table because there is
 * exactly one per store and every checkout has to read it: a join per cart
 * line to fetch a single short string would be all cost and no benefit.
 *
 * Nullable on purpose. Stores that already trade must stay valid accounts
 * after this ships; they are asked for the number the next time they are
 * about to take an order, not locked out retroactively.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendors', 'lipa_namba')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->string('lipa_namba', 32)->nullable()->after('phone');
        });
    }

    /**
     * Drops only the column this migration added. Nothing else on `vendors`
     * is touched, so a rollback cannot cost a store its profile.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('vendors', 'lipa_namba')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('lipa_namba');
        });
    }
};
