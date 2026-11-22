<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        // The customer's choice, e.g. {"Size": "M"}; null when the product has
        // no options. options_key fingerprints it so the same product in two
        // sizes is two lines, and the same size twice is one.
        'options',
        'options_key',
    ];

    /** Internal fingerprint; clients identify a line by its id. */
    protected $hidden = ['options_key'];

    /** Always present on the wire as an object, or null for no options. */
    public function getOptionsAttribute($value): ?array
    {
        return \App\Support\ProductOptions::present($value);
    }

    public function setOptionsAttribute($value): void
    {
        $value = \App\Support\ProductOptions::present($value);
        $this->attributes['options'] = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected $table = 'cart_items'; // ✅ make sure this matches your table name

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
