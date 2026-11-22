<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universities and colleges a customer can ask to be delivered to, and the
 * link from a saved address to one of them.
 *
 * `delivery_fee` is optional: NULL means "price this like any other address",
 * a number means a flat campus rate set by an administrator.
 *
 * `addresses.campus_id` is nullable and nulls itself if a campus is ever
 * deleted, so removing a campus can never take a customer's address with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campuses')) {
            Schema::create('campuses', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('short_name')->nullable();
                $table->string('area')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->decimal('delivery_fee', 12, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                // The seeder matches on name, and two campuses with the same
                // name would be indistinguishable in the customer's picker.
                $table->unique('name');
                $table->index(['is_active', 'sort_order']);
            });
        }

        if (Schema::hasTable('addresses') && ! Schema::hasColumn('addresses', 'campus_id')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->foreignId('campus_id')->nullable()->after('user_id')
                    ->constrained('campuses')->nullOnDelete();
            });
        }
    }

    /**
     * Not reversed: addresses point at campuses once customers start using
     * them, and dropping either would silently lose where they live.
     */
    public function down(): void
    {
    }
};
