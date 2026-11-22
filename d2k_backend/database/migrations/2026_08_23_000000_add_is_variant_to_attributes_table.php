<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks an attribute as a choice the customer makes ("Size", "Colour"), as
 * opposed to a fact about the product ("Brand", "RAM").
 *
 * A product's values for such an attribute become the options a shopper must
 * pick from before adding it to the cart. Defaults to false, so every existing
 * attribute keeps behaving exactly as before until an administrator opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attributes') && ! Schema::hasColumn('attributes', 'is_variant')) {
            Schema::table('attributes', function (Blueprint $table) {
                $table->boolean('is_variant')->default(false)->after('input_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attributes', 'is_variant')) {
            Schema::table('attributes', function (Blueprint $table) {
                $table->dropColumn('is_variant');
            });
        }
    }
};
