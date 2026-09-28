<?php

namespace App\Http\Controllers;

use App\Services\MealPlanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MealPlanController extends Controller
{
    /** Regions the app offers; HR is priced from real shelf prices. */
    public const REGIONS = ['HR', 'BA', 'RS', 'SI', 'ME', 'MK', 'XK', 'DE', 'AT', 'FR', 'IT', 'ES', 'NL', 'BE', 'CH', 'PL', 'SE', 'GB', 'US'];

    public const CURRENCIES = ['EUR', 'BAM', 'RSD', 'MKD', 'CHF', 'PLN', 'SEK', 'GBP', 'USD'];

    public function store(Request $request, MealPlanner $planner)
    {
        $data = $request->validate([
            'id' => 'required|uuid',
            'preferences' => 'required|array|max:40',
            'region' => ['required', Rule::in(self::REGIONS)],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'language' => 'nullable|string|max:12',
        ]);
        abort_if(strlen(json_encode($data['preferences'])) > 8000, 422, 'Preferences are too long.');
        $user = $request->user();
        $key = 'meal-plan:'.$user->id.':'.$data['id'];
        // A retry of the same request returns the same plan and charges nothing.
        if (is_array($cached = Cache::get($key))) {
            return response()->json(['data' => [...$cached, 'ai_coins' => (int) $user->fresh()->ai_coins]]);
        }
        $cost = (int) config('fitness.meal_plan_coins');
        abort_unless(DB::table('users')->where('id', $user->id)->where('ai_coins', '>=', $cost)
            ->decrement('ai_coins', $cost), 402, 'Not enough AI coins for a meal plan.');
        try {
            $plan = $planner->plan($data['preferences'], $data['region'], $data['currency'], $data['language'] ?? 'en');
        } catch (\Throwable $error) {
            DB::table('users')->where('id', $user->id)->increment('ai_coins', $cost);
            report($error);
            abort(503, 'MarkAI could not plan this week. Your coins were refunded.');
        }
        Cache::put($key, ['plan' => $plan], now()->addDay());

        return response()->json(['data' => ['plan' => $plan, 'ai_coins' => (int) $user->fresh()->ai_coins]]);
    }
}
