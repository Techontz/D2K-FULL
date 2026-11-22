<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A university or college a customer can have their order delivered to. */
class Campus extends Model
{
    protected $table = 'campuses';

    protected $fillable = [
        'name', 'short_name', 'area', 'latitude', 'longitude',
        'delivery_fee', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'latitude'     => 'float',
        'longitude'    => 'float',
        'delivery_fee' => 'decimal:2',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** What the customer's picker lists: live campuses, in the admin's order. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /** The flat campus delivery rate, or null for normal delivery pricing. */
    public function flatDeliveryFee(): ?float
    {
        return $this->delivery_fee === null ? null : (float) $this->delivery_fee;
    }

    /** The compact shape embedded in an address. */
    public function toSummary(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'short_name' => $this->short_name,
        ];
    }
}
