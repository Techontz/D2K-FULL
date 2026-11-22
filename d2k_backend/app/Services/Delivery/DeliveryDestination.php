<?php

namespace App\Services\Delivery;

use App\Models\Address;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order is going, resolved from what checkout sends.
 *
 * A client may send a saved `address_id` (the customer's own), a `campus_id`,
 * a raw pin, or any mix. An explicit pin wins over the saved address's pin; a
 * campus comes from `campus_id` or, failing that, from the saved address.
 */
final class DeliveryDestination
{
    private function __construct(
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?Campus $campus,
        public readonly ?Address $address,
    ) {
    }

    public static function fromInput(array $data, ?User $user): self
    {
        $latitude  = isset($data['delivery_latitude']) ? (float) $data['delivery_latitude'] : null;
        $longitude = isset($data['delivery_longitude']) ? (float) $data['delivery_longitude'] : null;

        $address = null;
        if ($user && ! empty($data['address_id'])) {
            $address = $user->addresses()->find((int) $data['address_id']);
        }

        if ($address && ($latitude === null || $longitude === null)) {
            $latitude  = $address->latitude;
            $longitude = $address->longitude;
        }

        $campus = null;
        if (self::campusesExist()) {
            if (! empty($data['campus_id'])) {
                $campus = Campus::where('is_active', true)->find((int) $data['campus_id']);
            } elseif ($address && $address->campus_id) {
                $campus = $address->campus;
            }
        }

        if ($campus && ($latitude === null || $longitude === null)) {
            $latitude  = $campus->latitude;
            $longitude = $campus->longitude;
        }

        return new self($latitude, $longitude, $campus, $address);
    }

    /**
     * The delivery quote: a campus's flat fee when an administrator has set
     * one, otherwise the normal distance pricing.
     */
    public function quote(DeliveryQuoter $quoter): DeliveryQuote
    {
        $flat = $this->campus?->flatDeliveryFee();

        if ($flat !== null) {
            return DeliveryQuote::quoted($flat);
        }

        return $quoter->quote($this->latitude, $this->longitude);
    }

    /** The campus as checkout reports it, or null. */
    public function campusPayload(): ?array
    {
        return $this->campus ? $this->campus->toSummary() + [
            'delivery_fee' => $this->campus->flatDeliveryFee(),
        ] : null;
    }

    private static function campusesExist(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasTable('campuses') && Schema::hasColumn('addresses', 'campus_id');
    }
}
