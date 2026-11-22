<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Campus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The signed-in customer's delivery address book.
 *
 * Every query is scoped through `$request->user()->addresses()`, so a customer
 * can only ever reach their own rows — an id belonging to someone else simply
 * does not exist as far as this controller is concerned, and 404s.
 */
class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'addresses' => $this->collection($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $address = DB::transaction(function () use ($request, $data) {
            // The first address a customer saves becomes the default, otherwise
            // checkout would open with nothing selected.
            $first = ! $request->user()->addresses()->exists();

            $address = $request->user()->addresses()->create($data + [
                'is_default' => $data['is_default'] ?? $first,
            ]);

            if ($address->is_default) {
                $this->demoteOthers($request, $address->id);
            }

            return $address;
        });

        return response()->json([
            'message'   => 'Address saved.',
            'address'   => $this->present($address),
            'addresses' => $this->collection($request),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $data    = $this->validated($request, $address);

        DB::transaction(function () use ($request, $address, $data) {
            $address->update($data);

            if ($data['is_default'] ?? false) {
                $this->demoteOthers($request, $address->id);
            }
        });

        return response()->json([
            'message'   => 'Address updated.',
            'address'   => $this->present($address->fresh()),
            'addresses' => $this->collection($request),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        DB::transaction(function () use ($request, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            // Removing the default would otherwise leave the customer with
            // several addresses and none of them selected at checkout.
            if ($wasDefault) {
                $next = $request->user()->addresses()->orderBy('id')->first();
                $next?->update(['is_default' => true]);
            }
        });

        return response()->json([
            'message'   => 'Address removed.',
            'addresses' => $this->collection($request),
        ]);
    }

    /** Promote one address to the default delivery address. */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        DB::transaction(function () use ($request, $address) {
            $address->update(['is_default' => true]);
            $this->demoteOthers($request, $address->id);
        });

        return response()->json([
            'message'   => 'Default delivery address updated.',
            'addresses' => $this->collection($request),
        ]);
    }

    /**
     * Exactly one address may be the default, enforced in the same transaction
     * as the promotion so a failure cannot leave a customer with two or none.
     */
    private function demoteOthers(Request $request, int $keepId): void
    {
        $request->user()->addresses()
            ->where('id', '!=', $keepId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * Campus delivery: with a `campus_id`, the hostel/block/room line in
     * `details` is required and everything a campus already answers (region,
     * city, street, pin) defaults from the campus when not sent.
     *
     * On update, leaving `campus_id` out keeps the address's current campus
     * (older clients do not know the field); sending null clears it.
     */
    private function validated(Request $request, ?Address $existing = null): array
    {
        $campusesReady = $this->campusesReady();
        $campusSent    = $campusesReady && $request->has('campus_id');
        $campusId      = $campusSent ? $request->input('campus_id') : ($existing?->campus_id);
        $isCampus      = $campusesReady && ! empty($campusId);

        $data = $request->validate([
            'full_name'  => ['required', 'string', 'max:120'],
            'phone'      => ['required', 'string', 'max:40'],
            'campus_id'  => $campusesReady
                ? ['nullable', 'integer', Rule::exists('campuses', 'id')->where('is_active', true)]
                : ['prohibited'],
            // Optional: the mobile app's address form has no region field.
            // Stored as '' when absent (the column is NOT NULL), the same
            // value the address-book backfill uses for "unknown".
            'region'     => ['nullable', 'string', 'max:100'],
            'city'       => [$isCampus ? 'nullable' : 'required', 'string', 'max:100'],
            'district'   => ['nullable', 'string', 'max:100'],
            'street'     => ['nullable', 'string', 'max:180'],
            'details'    => [$isCampus ? 'required' : 'nullable', 'string', 'max:500'],
            'latitude'   => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'  => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ], [
            'campus_id.exists'  => 'Please choose a campus from the list.',
            'details.required'  => 'Please add your hostel, block and room.',
        ]);

        if (! $campusSent) {
            unset($data['campus_id']);
        }

        if (! $isCampus) {
            $data['region'] = $data['region'] ?? '';
        }

        // Clearing the campus also clears the street line that was only ever
        // the campus name filled in on the customer's behalf.
        if ($campusSent && ! $isCampus && $existing?->campus_id && ! array_key_exists('street', $data)
            && $existing->street === $existing->campus?->name) {
            $data['street'] = null;
        }

        if ($isCampus && ($campus = Campus::find($campusId))) {
            $data['region'] = ($data['region'] ?? null) ?: 'Dar es Salaam';
            $data['city']   = ($data['city'] ?? null) ?: ($campus->area ?: 'Dar es Salaam');
            $data['street'] = ($data['street'] ?? null) ?: $campus->name;

            if (($data['latitude'] ?? null) === null && ($data['longitude'] ?? null) === null
                && $campus->latitude !== null && $campus->longitude !== null) {
                $data['latitude']  = $campus->latitude;
                $data['longitude'] = $campus->longitude;
            }
        }

        return $data;
    }

    /** False until the campuses migration has run on this server. */
    private function campusesReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('campuses') && Schema::hasColumn('addresses', 'campus_id');
    }

    private function collection(Request $request): array
    {
        return $request->user()->addresses()
            ->when($this->campusesReady(), fn ($q) => $q->with('campus'))
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Address $address) => $this->present($address))
            ->all();
    }

    private function present(Address $address): array
    {
        return [
            'id'         => $address->id,
            'full_name'  => $address->full_name,
            'phone'      => $address->phone,
            'region'     => $address->region,
            'city'       => $address->city,
            'district'   => $address->district,
            'street'     => $address->street,
            'details'    => $address->details,
            'latitude'   => $address->latitude,
            'longitude'  => $address->longitude,
            'is_default' => $address->is_default,
            'formatted'  => $address->formatted,
            // {"id", "name", "short_name"} when this is a campus address.
            'campus'     => $address->campus_id ? $address->campus?->toSummary() : null,
        ];
    }
}
