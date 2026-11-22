<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one product sit in the cart more than once, in different options
 * (a shirt in M and the same shirt in L).
 *
 * `options` holds the chosen {"Size": "M"} object. `options_key` is a stable
 * fingerprint of it ('' for "no options"), so the database can still enforce
 * one line per user + product + choice — the guarantee the old
 * (user_id, product_id) unique index gave, widened by one column.
 *
 * Both columns are additive. Existing rows get NULL / '' and stay unique,
 * because the old index already guaranteed one row per user and product.
 * The old unique index is dropped only after its replacement exists: the
 * user_id foreign key needs an index that starts with user_id at all times,
 * and the new one does.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'cart_items_user_id_product_id_unique';
    private const NEW_UNIQUE = 'cart_items_user_product_options_unique';

    public function up(): void
    {
        if (! Schema::hasTable('cart_items')) {
            return;
        }

        Schema::table('cart_items', function (Blueprint $table) {
            if (! Schema::hasColumn('cart_items', 'options')) {
                $table->json('options')->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('cart_items', 'options_key')) {
                $table->string('options_key', 40)->default('')->after('options');
            }
        });

        if (! Schema::hasIndex('cart_items', self::NEW_UNIQUE)) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->unique(['user_id', 'product_id', 'options_key'], self::NEW_UNIQUE);
            });
        }

        if (Schema::hasIndex('cart_items', self::OLD_UNIQUE)) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
    }

    /**
     * Not reversed automatically: once a customer has the same product in two
     * sizes, restoring the narrower unique index would fail, and deleting cart
     * lines to make it fit is not something a rollback should decide.
     */
    public function down(): void
    {
    }
};
