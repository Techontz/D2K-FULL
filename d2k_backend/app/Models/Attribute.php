<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category_id',
        'input_type', 'unit', 'is_active', 'sort_order',
        // The customer chooses one of the product's values (size, colour…).
        'is_variant',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_variant' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function productAttributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /** Administrator-curated options, for select/multiselect attributes. */
    public function values()
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('value');
    }
}
