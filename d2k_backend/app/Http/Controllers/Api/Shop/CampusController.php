<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Campus;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * Universities and colleges a customer can choose for campus delivery.
 * Public: the picker is shown before a shopper has signed in.
 */
class CampusController extends Controller
{
    public function index(): JsonResponse
    {
        // A server that has the code but not yet the migration lists nothing
        // rather than failing.
        if (! Schema::hasTable('campuses')) {
            return response()->json(['campuses' => []]);
        }

        $campuses = Campus::query()
            ->listed()
            ->get()
            ->map(fn (Campus $campus) => [
                'id'           => $campus->id,
                'name'         => $campus->name,
                'short_name'   => $campus->short_name,
                'area'         => $campus->area,
                // Null means normal delivery pricing applies.
                'delivery_fee' => $campus->flatDeliveryFee(),
            ])
            ->values();

        return response()->json(['campuses' => $campuses]);
    }
}
