<?php

namespace App\Http\Controllers;

use App\Services\CroatianPrices;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Store chains and what a grocery cart costs at each. Only Croatia publishes
 * shelf prices (cijene.dev); every other region answers with no vendors.
 */
class PriceController extends Controller
{
    public function vendors(Request $request, CroatianPrices $prices)
    {
        $region = $request->validate(['region' => ['required', Rule::in(MealPlanController::REGIONS)]])['region'];

        return response()->json(['data' => ['vendors' => $region === 'HR' ? $prices->vendors() : []]]);
    }

    public function compare(Request $request, CroatianPrices $prices)
    {
        $data = $request->validate([
            'region' => ['required', Rule::in(MealPlanController::REGIONS)],
            'items' => 'present|array|max:300',
            'items.*.staple' => 'nullable|string|max:60',
            'items.*.price' => 'nullable|numeric|min:0|max:100000',
        ]);
        $live = $data['region'] === 'HR';

        return response()->json(['data' => [
            'vendors' => $live ? $prices->compare($data['items']) : [],
            'price_date' => $live ? $prices->priceDate() : null,
        ]]);
    }
}
