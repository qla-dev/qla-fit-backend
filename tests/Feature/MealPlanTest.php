<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CroatianPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlanTest extends TestCase
{
    use RefreshDatabase;

    private function member(int $coins = 100): User
    {
        $user = User::factory()->create(['ai_coins' => $coins]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** A valid week in which every meal costs 1.25. */
    private function week(): array
    {
        return ['title' => 'Budget week', 'summary' => 'Cheap and simple.', 'days' => array_map(fn ($weekday) => [
            'weekday' => $weekday, 'cost' => 99,
            'meals' => [['slot' => 'lunch', 'name' => 'Grah', 'calories' => 600, 'protein' => 30, 'carbs' => 80, 'fat' => 12,
                'ingredients' => [['name' => 'beans', 'quantity' => 200, 'unit' => 'g', 'price' => 0.75],
                    ['name' => 'onions', 'quantity' => 1, 'unit' => 'piece', 'price' => 0.5]]]],
        ], range(1, 7))];
    }

    private function fake(array $plan, ?callable $onModel = null): void
    {
        config(['fitness.markai.key' => 'test', 'fitness.cijene.key' => 'cijene-test']);
        Http::fake([
            'api.cijene.dev/*' => Http::response(['products' => [['name' => 'Grah 500g', 'quantity' => '500', 'unit' => 'g',
                'chains' => [['chain' => 'konzum', 'avg_price' => '1.89'], ['chain' => 'lidl', 'avg_price' => '1.49']]]]]),
            'openrouter.ai/*' => function (Request $request) use ($plan, $onModel) {
                $onModel && $onModel($request);

                return Http::response(['choices' => [['message' => ['content' => json_encode($plan)]]]]);
            },
        ]);
    }

    private function body(array $overrides = []): array
    {
        return ['id' => (string) Str::uuid(), 'preferences' => ['diet' => 'any', 'budget' => 60],
            'region' => 'HR', 'currency' => 'EUR', 'language' => 'hr', ...$overrides];
    }

    public function test_croatian_plans_are_priced_from_todays_shelf_prices(): void
    {
        $this->member();
        $prompt = null;
        $this->fake($this->week(), function (Request $request) use (&$prompt) {
            $prompt = $request['messages'][1]['content'];
        });

        $response = $this->postJson('/api/markai/meal-plans', $this->body())->assertOk()
            ->assertJsonPath('data.plan.prices', 'cijene')->assertJsonPath('data.plan.currency', 'EUR')
            ->assertJsonPath('data.ai_coins', 97);

        // The cheapest chain's price reaches the model.
        $this->assertStringContainsString('beans: 1.49 EUR per 500 g', $prompt);
        // A day's cost is what its ingredients add up to, not the model's figure.
        $response->assertJsonPath('data.plan.days.0.cost', 1.25)->assertJsonPath('data.plan.weekly_cost', 8.75);
    }

    public function test_a_retry_returns_the_same_plan_without_charging_again(): void
    {
        $user = $this->member();
        $this->fake($this->week());
        $body = $this->body();
        $first = $this->postJson('/api/markai/meal-plans', $body)->assertOk()->json('data.plan');
        $this->postJson('/api/markai/meal-plans', $body)->assertOk()->assertJsonPath('data.plan', $first);
        $this->assertSame(97, $user->fresh()->ai_coins);
    }

    public function test_a_failed_plan_refunds_and_an_empty_wallet_is_refused(): void
    {
        $user = $this->member();
        $bad = $this->week();
        array_pop($bad['days']);
        $this->fake($bad);
        $this->postJson('/api/markai/meal-plans', $this->body())->assertStatus(503);
        $this->assertSame(100, $user->fresh()->ai_coins);

        $this->member(2);
        $this->postJson('/api/markai/meal-plans', $this->body())->assertStatus(402);
    }

    public function test_other_regions_are_estimated_in_their_currency(): void
    {
        $this->member();
        $this->fake($this->week());
        $this->postJson('/api/markai/meal-plans', $this->body(['region' => 'BA', 'currency' => 'BAM']))->assertOk()
            ->assertJsonPath('data.plan.prices', 'estimate')->assertJsonPath('data.plan.currency', 'BAM')
            ->assertJsonPath('data.plan.price_date', null);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'cijene.dev'));
        $this->postJson('/api/markai/meal-plans', $this->body(['region' => 'XX']))->assertUnprocessable();
    }

    public function test_the_staple_basket_is_fetched_once_a_day(): void
    {
        $this->member();
        $this->fake($this->week());
        $this->postJson('/api/markai/meal-plans', $this->body())->assertOk();
        $this->postJson('/api/markai/meal-plans', $this->body())->assertOk();
        $calls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'cijene.dev'))->count();
        $this->assertSame(count(CroatianPrices::STAPLES), $calls);
        $this->assertTrue(Cache::has('cijene:staples:'.now()->toDateString()));
    }
}
