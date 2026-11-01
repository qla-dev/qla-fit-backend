<?php

namespace App\Http\Controllers;

use App\Services\ShelfPrices;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Store chains and what a grocery cart costs at each, in the regions with
 * real shelf prices (ShelfPrices::REGIONS); every other region answers with
 * no vendors.
 */
class PriceController extends Controller
{
    public function vendors(Request $request)
    {
        $region = $request->validate(['region' => ['required', Rule::in(MealPlanController::REGIONS)]])['region'];

        return response()->json(['data' => ['vendors' => ShelfPrices::for($region)?->vendors() ?? []]]);
    }

    public function compare(Request $request)
    {
        $data = $request->validate([
            'region' => ['required', Rule::in(MealPlanController::REGIONS)],
            'items' => 'present|array|max:300',
            'items.*.staple' => 'nullable|string|max:60',
            'items.*.price' => 'nullable|numeric|min:0|max:100000',
        ]);
        $prices = ShelfPrices::for($data['region']);

        return response()->json(['data' => [
            'vendors' => $prices?->compare($data['items']) ?? [],
            'price_date' => $prices?->priceDate(),
        ]]);
    }
}
