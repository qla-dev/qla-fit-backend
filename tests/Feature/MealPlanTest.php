<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CroatianPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
                'ingredients' => [['name' => 'beans', 'quantity' => 200, 'unit' => 'g', 'price' => 0.75, 'staple' => 'Beans'],
                    ['name' => 'onions', 'quantity' => 1, 'unit' => 'piece', 'price' => 0.5, 'staple' => 'shallots']]]],
        ], range(1, 7))];
    }

    private function listing(string $chain, string $name, string $avg, array $extra = []): array
    {
        return ['chain' => $chain, 'code' => Str::random(6), 'name' => $name, 'brand' => null, 'category' => 'HRANA',
            'unit' => 'kom', 'quantity' => null, 'min_price' => $avg, 'max_price' => $avg, 'avg_price' => $avg,
            'price_date' => '2026-09-28', ...$extra];
    }

    /**
     * cijene.dev search results by query. Beans per kg across chains: a 0.10
     * clearance line, then 2.00, 3.00, 4.00 and 5.00, plus a bean salad; milk
     * that is body lotion; lemons both loose and as syrup; eggs by the ten.
     */
    private function searches(): array
    {
        return [
            'grah' => [
                ['ean' => '3850000000011', 'quantity' => '0.500', 'unit' => 'kg', 'chains' => [$this->listing('konzum', 'GRAH BIJELI 500 g', '1.00')]],
                ['quantity' => '0.400', 'unit' => 'kg', 'chains' => [$this->listing('konzum', 'GRAH CRVENI 400 g', '1.20')]],
                ['quantity' => null, 'unit' => null, 'chains' => [
                    $this->listing('lidl', 'Grah smeđi', '4.00', ['unit' => 'kg', 'quantity' => '1']),
                    $this->listing('lidl', 'Grah rasprodaja', '0.10', ['unit' => 'kg', 'quantity' => '1']),
                    $this->listing('lidl', 'Grah pinto', '5.00', ['unit' => 'kg', 'quantity' => '1']),
                ]],
                ['quantity' => '0.300', 'unit' => 'kg', 'chains' => [$this->listing('konzum', 'GRAH SALATA 300 g', '0.30')]],
            ],
            'mlijeko' => [['quantity' => '0.250', 'unit' => 'L', 'chains' => [
                $this->listing('konzum', 'MLIJEKO ZA TIJELO 250 ml', '5.00', ['category' => 'Kozmetika'])]]],
            'limun kg' => [
                ['quantity' => null, 'unit' => null, 'chains' => [$this->listing('studenac', 'LIMUN kg', '1.49', ['unit' => 'kg'])]],
                ['quantity' => '1.000', 'unit' => 'L', 'chains' => [$this->listing('konzum', 'LIMUN SIRUP 1l', '2.89', ['unit' => 'kg'])]],
            ],
            'jaja' => [['quantity' => null, 'unit' => null, 'chains' => [
                $this->listing('studenac', 'JAJA RAZ.A KLASA M 10 kom', '2.50', ['quantity' => '0,600'])]]],
        ];
    }

    private function fake(array $plan, ?callable $onModel = null): void
    {
        config(['fitness.markai.key' => 'test', 'fitness.cijene.key' => 'cijene-test']);
        $searches = $this->searches();
        Http::fake([
            'api.cijene.dev/v1/products/*' => function (Request $request) use ($searches) {
                return Http::response(['products' => $searches[$request->data()['q'] ?? ''] ?? []]);
            },
            'api.cijene.dev/v1/chains/*' => Http::response(['chains' => ['konzum', 'lidl', 'studenac', 'trgovina-krk']]),
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

    private function cijeneRequests(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'cijene.dev'))->count();
    }

    public function test_the_api_becomes_a_basket_of_real_staple_prices(): void
    {
        $this->fake($this->week());
        $this->artisan('prices:refresh')->assertSuccessful();
        $prices = app(CroatianPrices::class);

        $this->assertSame('2026-09-28', $prices->priceDate());
        $staples = $prices->staples();
        // Listings are compared per kg (pack prices divided by their size);
        // the 25th percentile of 0.10, 2.00, 3.00, 4.00, 5.00 is 2.00, not the
        // clearance line. 'GRAH SALATA' (bean salad) is excluded.
        $this->assertSame(2.0, $staples['beans']['price']);
        $this->assertSame('kg', $staples['beans']['unit']);
        $this->assertSame('GRAH BIJELI 500 g', $staples['beans']['product']);
        // Loose lemons only, never the syrup; eggs by the piece.
        $this->assertSame(['price' => 1.49, 'unit' => 'kg'], array_intersect_key($staples['lemons'], ['price' => 0, 'unit' => 0]));
        $this->assertSame(['price' => 0.25, 'unit' => 'piece'], array_intersect_key($staples['eggs'], ['price' => 0, 'unit' => 0]));
        // Body lotion never prices food.
        $this->assertArrayNotHasKey('milk', $staples);
        // Every staple is one search, all sent with the key.
        $this->assertSame(count(CroatianPrices::STAPLES), $this->cijeneRequests());
        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'cijene.dev')
            || $request->hasHeader('Authorization', 'Bearer cijene-test'));

        // Already fetched today: reading the basket asks nothing more.
        $prices->staples();
        $this->assertSame(count(CroatianPrices::STAPLES), $this->cijeneRequests());
    }

    public function test_croatian_plans_are_priced_from_the_basket_fetched_on_the_first_plan(): void
    {
        $this->member();
        $request = null;
        $this->fake($this->week(), function (Request $sent) use (&$request) {
            $request = $sent;
        });

        $response = $this->postJson('/api/markai/meal-plans', $this->body())->assertOk()
            ->assertJsonPath('data.plan.prices', 'cijene')->assertJsonPath('data.plan.currency', 'EUR')
            ->assertJsonPath('data.plan.price_date', '2026-09-28')->assertJsonPath('data.ai_coins', 97);

        $this->assertStringContainsString('- beans: 2 EUR per kg', $request['messages'][1]['content']);
        // No thinking pass before a week of JSON.
        $this->assertSame(['effort' => 'none'], $request['reasoning']);
        // A day's cost is what its ingredients add up to, not the model's figure.
        $response->assertJsonPath('data.plan.days.0.cost', 1.25)->assertJsonPath('data.plan.weekly_cost', 8.75);
        // An ingredient priced from the basket carries its staple and the
        // barcode the app finds its photo by; a made-up staple is dropped.
        $response->assertJsonPath('data.plan.days.0.meals.0.ingredients.0', ['name' => 'beans', 'quantity' => 200,
            'unit' => 'g', 'price' => 0.75, 'staple' => 'beans', 'product' => 'GRAH BIJELI 500 g', 'ean' => '3850000000011']);
        $this->assertSame(['name' => 'onions', 'quantity' => 1, 'unit' => 'piece', 'price' => 0.5],
            $response->json('data.plan.days.0.meals.0.ingredients.1'));
    }

    public function test_vendors_come_from_the_api_and_each_chain_is_priced_on_its_own(): void
    {
        $this->fake($this->week());

        $this->getJson('/api/prices/vendors?region=HR')->assertOk()->assertJsonPath('data.vendors', [
            ['code' => 'konzum', 'name' => 'Konzum'], ['code' => 'lidl', 'name' => 'Lidl'],
            ['code' => 'studenac', 'name' => 'Studenac'], ['code' => 'trgovina-krk', 'name' => 'Trgovina Krk'],
        ]);
        $this->getJson('/api/prices/vendors?region=BA')->assertOk()->assertJsonPath('data.vendors', []);
        // Konzum's beans are 2.00 and 3.00 per kg, Lidl's 0.10, 4.00 and 5.00.
        $this->assertSame(['konzum' => 2.0, 'lidl' => 0.1], app(CroatianPrices::class)->staples()['beans']['chains']);
    }

    public function test_a_cart_is_compared_across_chains(): void
    {
        $this->fake($this->week());
        $items = [['staple' => 'beans', 'price' => 1.0], ['staple' => null, 'price' => 0.5]];

        // Beans scale by the chain's price over the typical 2.00; the rest
        // keeps its own price. Studenac and Trgovina Krk have no beans.
        $this->postJson('/api/prices/compare', ['region' => 'HR', 'items' => $items])->assertOk()
            ->assertJsonPath('data.price_date', '2026-09-28')
            ->assertJsonPath('data.vendors', [
                ['code' => 'lidl', 'name' => 'Lidl', 'total' => 0.55, 'matched' => 1, 'prices' => [0.05, 0.5]],
                ['code' => 'konzum', 'name' => 'Konzum', 'total' => 1.5, 'matched' => 1, 'prices' => [1, 0.5]],
            ]);
        $this->postJson('/api/prices/compare', ['region' => 'DE', 'items' => $items])->assertOk()
            ->assertJsonPath('data.vendors', []);
    }

    public function test_without_a_key_croatian_plans_are_estimated(): void
    {
        $this->member();
        $this->fake($this->week());
        config(['fitness.cijene.key' => null]);

        $this->postJson('/api/markai/meal-plans', $this->body())->assertOk()
            ->assertJsonPath('data.plan.prices', 'estimate')->assertJsonPath('data.plan.price_date', null);
        $this->assertSame(0, $this->cijeneRequests());
    }

    public function test_a_failed_refresh_keeps_the_last_good_prices(): void
    {
        $this->fake($this->week());
        $this->artisan('prices:refresh');
        $this->travel(1)->days();
        Http::fake(['api.cijene.dev/*' => Http::response('down', 500)]);

        $this->assertSame(2.0, app(CroatianPrices::class)->staples()['beans']['price']);
        $this->assertSame('2026-09-28', app(CroatianPrices::class)->priceDate());
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
}
