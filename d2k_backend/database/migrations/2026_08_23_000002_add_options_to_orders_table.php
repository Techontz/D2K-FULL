<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The options the customer chose for this line, e.g. {"Size": "M"}.
 *
 * Orders are one row per product, so one nullable JSON column is enough.
 * Historic orders stay NULL, which reads as "no options".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'options')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->json('options')->nullable()->after('total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'options')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('options');
            });
        }
    }
};
