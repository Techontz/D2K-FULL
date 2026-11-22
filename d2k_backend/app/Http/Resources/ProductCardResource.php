<?php

namespace App\Http\Resources;

use App\Support\Media;
use App\Support\Money;
use App\Support\ProductOptions;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The compact product shape used by every grid, shelf and carousel.
 *
 * Deliberately smaller than the detail payload: listing pages render dozens of
 * these at a time, so descriptions, attributes and reviews are left out.
 */
class ProductCardResource extends JsonResource
{
    public function toArray($request): array
    {
        $images = Media::urls($this->images->pluck('image'));

        // `reviews_avg_rating` / `reviews_count` are supplied by withAvg/withCount
        // on the listing queries so this never triggers a per-row query.
        $rating = $this->reviews_avg_rating
            ?? ($this->relationLoaded('reviews') ? $this->reviews->avg('rating') : null);
        $reviewCount = $this->reviews_count
            ?? ($this->relationLoaded('reviews') ? $this->reviews->count() : 0);

        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'image' => $images[0] ?? null,
            'images' => $images,
            'price' => Money::payload($this->new_price, $this->old_price),
            'rating' => [
                'average' => $rating ? round((float) $rating, 1) : 0.0,
                'count'   => (int) $reviewCount,
            ],
            'stock'    => (int) $this->stock,
            'in_stock' => $this->stock > 0,
            // True when the shopper must choose a size/colour first, so a grid
            // "add to cart" opens the product instead. Read from the
            // attributeValues relation the Product model always eager-loads,
            // so this costs no query per card.
            'has_options' => ProductOptions::forProduct($this->resource) !== [],
            'category' => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ]),
            'subcategory' => $this->whenLoaded('subcategory', fn () => [
                'id'   => $this->subcategory->id,
                'name' => $this->subcategory->name,
            ]),
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->id,
                'name' => $this->vendor->business_name,
            ]),
            'badges' => [
                // Mirrors the reference storefront's card badges. Derived from
                // real columns — nothing here is decorative invention.
                'low_stock'  => $this->stock > 0 && $this->stock <= 5,
                'out_of_stock' => $this->stock <= 0,
                'discounted' => $this->old_price !== null && (float) $this->old_price > (float) $this->new_price,
            ],
        ];
    }
}
