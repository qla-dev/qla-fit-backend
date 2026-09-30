<?php

namespace App\Http\Controllers;

use App\Services\RevenueCatPurchases;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the account bought in the app: its coins and unlocked programs. */
class PurchaseController extends Controller
{
    public function index(Request $request, RevenueCatPurchases $purchases)
    {
        return response()->json(['data' => $this->state($request, $purchases)]);
    }

    /** Called by the app after a store purchase, and to restore purchases. */
    public function store(Request $request, RevenueCatPurchases $purchases)
    {
        $products = [...array_keys(config('purchases.coin_products')), ...array_keys(config('purchases.program_products'))];
        $data = $request->validate([
            'product_id' => ['required', 'string', Rule::in($products)],
            'program_id' => ['nullable', 'string', 'max:80'],
        ]);
        $programId = $data['program_id'] ?? null;
        if (isset(config('purchases.program_products')[$data['product_id']])) {
            // A program price only unlocks a program sold at that price.
            abort_unless($programId && (config('purchases.programs')[$programId] ?? null) === $data['product_id'], 422,
                'This program is not sold at that price.');
        }
        $granted = $purchases->sync($request->user(), $data['product_id'], $programId);

        return response()->json(['data' => [...$this->state($request, $purchases), 'granted' => $granted]]);
    }

    private function state(Request $request, RevenueCatPurchases $purchases): array
    {
        return ['ai_coins' => (int) $request->user()->fresh()->ai_coins, 'programs' => $purchases->programs($request->user())];
    }
}
