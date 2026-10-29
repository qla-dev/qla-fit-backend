<?php

namespace App\Http\Controllers;

use App\Services\RevenueCatPurchases;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the account bought in the app: its coins and the programs its training level unlocks. */
class PurchaseController extends Controller
{
    public function index(Request $request, RevenueCatPurchases $purchases)
    {
        $purchases->refreshTraining($request->user());

        return response()->json(['data' => $this->state($request, $purchases)]);
    }

    /** Called by the app after a store purchase, and to restore purchases. */
    public function store(Request $request, RevenueCatPurchases $purchases)
    {
        $products = [...array_keys(config('purchases.coin_products')), ...array_keys(config('purchases.training_products'))];
        $data = $request->validate([
            'product_id' => ['required', 'string', Rule::in($products)],
            'program_id' => ['nullable', 'string', Rule::in(array_keys(config('purchases.programs')))],
        ]);
        $granted = $purchases->sync($request->user(), $data['product_id']);
        $state = $this->state($request, $purchases);
        // The store took the subscription but RevenueCat has not reported it
        // yet: the app offers to restore, which asks again.
        abort_if(isset($data['program_id']) && ! in_array($data['program_id'], $state['programs'], true), 402,
            'The subscription has not been confirmed yet. Try again in a moment.');

        return response()->json(['data' => [...$state, 'granted' => $granted]]);
    }

    private function state(Request $request, RevenueCatPurchases $purchases): array
    {
        $user = $request->user()->fresh();

        return ['ai_coins' => (int) $user->ai_coins, 'training_level' => $purchases->level($user), 'programs' => $purchases->programs($user)];
    }
}
