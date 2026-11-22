<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'vendor_id',
        'product_id',
        'quantity',
        'price',
        'total',
        'delivery_fee',
        'status',
        'payment_provider',
        'payment_method',
        // Direct2Kariakoo's payment details as they stood when this order was
        // placed. Snapshotted, never read back off the current settings: an
        // administrator changing the till must not rewrite what past customers
        // were told to pay.
        'lipa_namba',
        'payment_account_name',
        // Placing an order is not paying for it. Stays pending_payment until
        // someone at D2K confirms the money actually arrived.
        'payment_status',
        'delivery_address',
        'customer_phone',
        'external_id',
        // What the customer chose for this line, e.g. {"Size": "M"}.
        'options',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'total'        => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'quantity'     => 'integer',
    ];

    /** Null when the line has no options (or predates them). */
    public function getOptionsAttribute($value): ?array
    {
        return \App\Support\ProductOptions::present($value);
    }

    public function setOptionsAttribute($value): void
    {
        $value = \App\Support\ProductOptions::present($value);
        $this->attributes['options'] = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
