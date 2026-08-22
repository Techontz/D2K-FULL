<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes only — no column is added, changed or dropped.
 *
 * These cover the three queries the order screens actually run: a customer's
 * own history (user_id, newest first), a seller's queue (vendor_id + status),
 * and the admin filtering by payment state. Without them each is a full scan
 * of `orders`, which grows with every sale.
 *
 * Measured context, so this is not cargo-culted: on the live server a single
 * trivial query costs ~2.4s of *connection* time and the whole of shop/home's
 * query work costs ~0.7s. Indexes are therefore not the current bottleneck —
 * they are here so these queries stay cheap as the table grows, not to fix
 * today's latency.
 */
return new class extends Migration
{
    /** Index name => columns. */
    private array $indexes = [
        'orders_user_created_index'    => ['user_id', 'created_at'],
        'orders_vendor_status_index'   => ['vendor_id', 'status'],
        'orders_payment_status_index'  => ['payment_status'],
    ];

    public function up(): void
    {
        $existing = $this->existingIndexNames();

        Schema::table('orders', function (Blueprint $table) use ($existing) {
            foreach ($this->indexes as $name => $columns) {
                // payment_status only exists once the payment migration has
                // run; skip rather than fail on a half-migrated database.
                foreach ($columns as $column) {
                    if (! Schema::hasColumn('orders', $column)) {
                        continue 2;
                    }
                }

                if (! in_array($name, $existing, true)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    public function down(): void
    {
        $existing = $this->existingIndexNames();

        Schema::table('orders', function (Blueprint $table) use ($existing) {
            foreach (array_keys($this->indexes) as $name) {
                if (in_array($name, $existing, true)) {
                    $table->dropIndex($name);
                }
            }
        });
    }

    /** Index names already on `orders`, so a re-run cannot duplicate one. */
    private function existingIndexNames(): array
    {
        try {
            return array_keys(
                Schema::getConnection()->getSchemaBuilder()->getIndexes('orders')
                    ? collect(Schema::getConnection()->getSchemaBuilder()->getIndexes('orders'))
                        ->keyBy('name')->all()
                    : []
            );
        } catch (\Throwable $e) {
            return [];
        }
    }
};
