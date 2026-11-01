<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KrogerPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KrogerPricesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['fitness.markai.key' => 'test', 'fitness.prices.kroger' => [
            'client_id' => 'test-client', 'client_secret' => 'test-secret', 'zip' => '45202',
        ]]);
    }

    private function product(string $name, string $size, ?float $price, string $soldBy = 'Unit'): array
    {
        return ['upc' => '0001111042315', 'description' => $name, 'brand' => 'Kroger',
            'categories' => [str_starts_with($name, 'Fresh ') ? 'Produce' : 'Grocery'],
            'items' => [['size' => $size, 'soldBy' => $soldBy, 'price' => ['regular' => $price, 'promo' => 0.01]]]];
    }

    private function fake(?callable $products = null, int $tokenStatus = 200, int $locationStatus = 200): void
    {
        Http::fake([
            KrogerPrices::TOKEN_URL => Http::response(['access_token' => 'test-token', 'expires_in' => 1800], $tokenStatus),
            KrogerPrices::LOCATIONS_URL.'*' => Http::response(['data' => [['locationId' => '01400999', 'chain' => 'KROGER', 'name' => 'Test Store']]], $locationStatus),
            KrogerPrices::PRODUCTS_URL.'*' => $products ?? function (Request $request) {
                $data = match ($request['filter.term']) {
                    'pinto beans' => [
                        $this->product('Kroger Pinto Beans', '1 lb', 1.0),
                        $this->product('Kroger Pinto Beans', '16 oz', 2.0),
                        $this->product('Kroger Pinto Beans', '2 lb', 6.0),
                        $this->product('Kroger Pinto Beans', '1 lb', 4.0),
                        $this->product('Kroger Pinto Beans', '1 lb', 5.0),
                        $this->product('Kroger Pinto Beans', '1 lb', null),
                        $this->product('Kroger Pinto Beans Soup', '1 lb', 0.01),
                    ],
                    'eggs' => [$this->product('Kroger Large Eggs', '12 ct', 3.6)],
                    'milk' => [
                        $this->product('Kroger Whole Milk', '1 gal', 3.79),
                        $this->product('Milk Body Lotion', '1 gal', 0.01),
                        $this->product('Almond Milk', '1 gal', 0.01),
                        $this->product('Milk Chocolate Candy', '1 gal', 0.01),
                        [...$this->product('Milk', '1 gal', 0.01), 'categories' => ['Personal Care']],
                    ],
                    'bananas' => [$this->product('Fresh Bananas', 'approx 1.5 lb', 0.59, 'Weight')],
                    default => [],
                };

                return Http::response(['data' => $data]);
            },
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'title' => 'Budget week', 'summary' => 'Simple meals', 'days' => array_map(fn ($day) => [
                    'weekday' => $day, 'cost' => 1, 'meals' => [['slot' => 'lunch', 'name' => 'Beans',
                        'calories' => 600, 'protein' => 30, 'carbs' => 80, 'fat' => 12,
                        'ingredients' => [['name' => 'beans', 'quantity' => 200, 'unit' => 'g', 'price' => 1, 'staple' => 'beans']]]],
                ], range(1, 7)),
            ])]]]]),
        ]);
    }

    private function plan()
    {
        Sanctum::actingAs(User::factory()->create(['ai_coins' => 100]));

        return $this->postJson('/api/markai/meal-plans', ['id' => (string) Str::uuid(), 'preferences' => ['diet' => 'any'],
            'region' => 'US', 'currency' => 'EUR', 'language' => 'en']);
    }

    private function sent(string $url): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), $url))->count();
    }

    public function test_us_basket_uses_regular_unit_prices_and_is_cached_daily(): void
    {
        $this->fake();
        $this->artisan('prices:refresh', ['region' => 'US'])->assertSuccessful();
        $source = app(KrogerPrices::class);
        $basket = $source->staples();
        // 25th percentile of 1,2,3,4,5 USD/lb is 2 USD/lb, not the cheapest or promo price.
        $this->assertSame(['price' => 4.41, 'unit' => 'kg', 'product' => 'Kroger Pinto Beans',
            'chain' => 'kroger', 'ean' => '0001111042315', 'chains' => ['kroger' => 4.41], 'products' => 5], $basket['beans']);
        $this->assertSame([0.3, 'piece'], [$basket['eggs']['price'], $basket['eggs']['unit']]);
        $this->assertSame([1.0, 'l', 1], [$basket['milk']['price'], $basket['milk']['unit'], $basket['milk']['products']]);
        $this->assertSame([1.3, 'kg'], [$basket['bananas']['price'], $basket['bananas']['unit']]);
        $this->assertSame(now()->toDateString(), $source->priceDate());
        $source->staples();
        $this->assertSame(1, $this->sent(KrogerPrices::TOKEN_URL));
        $this->assertSame(1, $this->sent(KrogerPrices::LOCATIONS_URL));
        $this->assertSame(count(KrogerPrices::STAPLES), $this->sent(KrogerPrices::PRODUCTS_URL));
        Http::assertSent(fn (Request $r) => $r->url() === KrogerPrices::TOKEN_URL && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('test-client:test-secret'))
            && $r->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $r['grant_type'] === 'client_credentials' && $r['scope'] === 'product.compact');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), KrogerPrices::LOCATIONS_URL)
            && $r['filter.zipCode.near'] === '45202' && $r['filter.limit'] === 1);
        foreach (Http::recorded(fn (Request $r) => str_starts_with($r->url(), KrogerPrices::PRODUCTS_URL)) as [$request]) {
            $this->assertSame(50, $request['filter.limit']);
            $this->assertSame('01400999', $request['filter.locationId']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-token'));
        }
        $this->getJson('/api/prices/vendors?region=US')->assertOk()
            ->assertJsonPath('data.vendors', [['code' => 'kroger', 'name' => 'Kroger']]);
        $this->postJson('/api/prices/compare', ['region' => 'US', 'items' => [['staple' => 'beans', 'price' => 2]]])
            ->assertOk()->assertJsonPath('data.vendors.0.total', 2)->assertJsonPath('data.vendors.0.matched', 1);
    }

    public function test_us_plan_uses_kroger_usd_and_upc(): void
    {
        $this->fake();
        $this->plan()->assertOk()->assertJsonPath('data.plan.prices', 'kroger')->assertJsonPath('data.plan.currency', 'USD')
            ->assertJsonPath('data.plan.price_date', now()->toDateString())
            ->assertJsonPath('data.plan.days.0.meals.0.ingredients.0.ean', '0001111042315');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openrouter.ai')
            && str_contains($r['messages'][1]['content'], 'US (Kroger) shelf prices in USD')
            && str_contains($r['messages'][1]['content'], '- beans: 4.41 USD per kg'));
    }

    public function test_fresh_and_frozen_staples_do_not_admit_other_groceries(): void
    {
        $this->fake(fn () => Http::response(['data' => [
            [...$this->product('Garlic Bologna', '1 lb', 0.01), 'categories' => ['Deli']],
            [...$this->product('Carrots', '15 oz', 0.01), 'categories' => ['Canned & Packaged']],
            [...$this->product('Mixed Vegetables', '15 oz', 0.01), 'categories' => ['Canned & Packaged']],
            [...$this->product('Garlic', '1 ea', 0.5), 'categories' => ['Produce']],
            [...$this->product('Carrots', '1 lb', 1.0), 'categories' => ['Produce']],
            [...$this->product('Mixed Vegetables', '16 oz', 2.0), 'temperature' => ['indicator' => 'FROZEN']],
        ]]));
        $basket = app(KrogerPrices::class)->staples();
        $this->assertSame([0.5, 'piece', 1], [$basket['garlic']['price'], $basket['garlic']['unit'], $basket['garlic']['products']]);
        $this->assertSame([2.2, 'kg', 1], [$basket['carrots']['price'], $basket['carrots']['unit'], $basket['carrots']['products']]);
        $this->assertSame([4.41, 'kg', 1], [$basket['frozen vegetables']['price'], $basket['frozen vegetables']['unit'], $basket['frozen vegetables']['products']]);
    }

    #[DataProvider('missingCredentials')]
    public function test_missing_credentials_leave_us_plans_estimated(string $key): void
    {
        config(['fitness.prices.kroger.'.$key => null]);
        $this->fake();
        $this->plan()->assertOk()->assertJsonPath('data.plan.prices', 'estimate')->assertJsonPath('data.plan.currency', 'EUR')
            ->assertJsonPath('data.plan.price_date', null)->assertJsonMissingPath('data.plan.days.0.meals.0.ingredients.0.staple');
        $this->assertSame(0, $this->sent('https://api.kroger.com'));
    }

    public static function missingCredentials(): array
    {
        return [['client_id'], ['client_secret']];
    }

    public function test_removing_credentials_disables_an_existing_basket(): void
    {
        $this->fake();
        $this->assertNotEmpty(app(KrogerPrices::class)->staples());
        $calls = $this->sent('https://api.kroger.com');
        config(['fitness.prices.kroger.client_secret' => null]);
        $this->plan()->assertOk()->assertJsonPath('data.plan.prices', 'estimate')->assertJsonPath('data.plan.price_date', null);
        $this->getJson('/api/prices/vendors?region=US')->assertOk()->assertJsonPath('data.vendors', []);
        $this->postJson('/api/prices/compare', ['region' => 'US', 'items' => []])->assertOk()
            ->assertJsonPath('data.vendors', [])->assertJsonPath('data.price_date', null);
        $this->assertSame($calls, $this->sent('https://api.kroger.com'));
    }

    #[DataProvider('failurePoints')]
    public function test_api_failure_leaves_plan_estimated_and_waits_30_minutes(string $failure): void
    {
        $this->fake($failure === 'products' ? fn () => Http::response([], 503) : null,
            $failure === 'token' ? 503 : 200, $failure === 'location' ? 503 : 200);
        $this->plan()->assertOk()->assertJsonPath('data.plan.prices', 'estimate')->assertJsonPath('data.plan.price_date', null);
        $calls = $this->sent('https://api.kroger.com');
        $this->plan()->assertOk()->assertJsonPath('data.plan.prices', 'estimate');
        $this->travel(29)->minutes();
        app(KrogerPrices::class)->staples();
        $this->assertSame($calls, $this->sent('https://api.kroger.com'));
        $this->travel(2)->minutes();
        $this->assertSame([], app(KrogerPrices::class)->staples());
        $this->assertGreaterThan($calls, $this->sent('https://api.kroger.com'));
    }

    public static function failurePoints(): array
    {
        return [['token'], ['location'], ['products']];
    }

    public function test_failed_refresh_keeps_last_good_basket_and_token_expires_early(): void
    {
        $down = false;
        $this->fake(function () use (&$down) {
            return $down ? Http::response([], 429) : Http::response(['data' => [$this->product('Kroger Whole Milk', '1 gal', 3.79)]]);
        });
        $source = app(KrogerPrices::class);
        $basket = $source->staples();
        $this->assertNotEmpty($basket);
        $this->assertTrue($source->refresh());
        $this->assertSame(1, $this->sent(KrogerPrices::TOKEN_URL));
        $this->travel(1741)->seconds();
        $this->assertTrue($source->refresh());
        $this->assertSame(2, $this->sent(KrogerPrices::TOKEN_URL));
        $down = true;
        $this->travel(1)->days();
        $this->assertSame($basket, $source->staples());
        $calls = $this->sent('https://api.kroger.com');
        $this->assertSame($basket, $source->staples());
        $this->assertSame($calls, $this->sent('https://api.kroger.com'));
        $this->assertSame(2, $this->sent(KrogerPrices::LOCATIONS_URL));
        // Changing the configured store area cannot reuse the old basket.
        config(['fitness.prices.kroger.zip' => '90210']);
        $this->assertSame([], $source->staples());
    }

    #[DataProvider('sizes')]
    public function test_us_sizes_are_normalized(string $staple, string $description, string $size, ?float $expected, string $unit): void
    {
        $this->fake(fn () => Http::response(['data' => [$this->product($description, $size, 12.0)]]));
        $basket = app(KrogerPrices::class)->staples();
        if ($expected === null) {
            $this->assertArrayNotHasKey($staple, $basket);
        } else {
            $this->assertSame([$expected, $unit], [$basket[$staple]['price'], $basket[$staple]['unit']]);
        }
    }

    public static function sizes(): array
    {
        return [
            ['beans', 'Pinto Beans', '1 lb', 26.46, 'kg'],
            ['beans', 'Pinto Beans', '16 oz', 26.46, 'kg'],
            ['beans', 'Pinto Beans', '2 x 16 oz', 13.23, 'kg'],
            ['beans', 'Pinto Beans', '500 g', 24.0, 'kg'],
            ['milk', 'Whole Milk', '1 gal', 3.17, 'l'],
            ['milk', 'Whole Milk', '1 qt', 12.68, 'l'],
            ['milk', 'Whole Milk', '1 pt', 25.36, 'l'],
            ['milk', 'Whole Milk', '16 fl oz', 25.36, 'l'],
            ['milk', 'Whole Milk', '16 fl. oz.', 25.36, 'l'],
            ['milk', 'Whole Milk', '0.5 gallon', 6.34, 'l'],
            ['milk', 'Whole Milk', '1/2 gal', 6.34, 'l'],
            ['beans', 'Pinto Beans', '1 1/2 lb', 17.64, 'kg'],
            ['milk', 'Whole Milk', '1/0 gal', null, 'l'],
            ['milk', 'Whole Milk', '1 l', 12.0, 'l'],
            ['eggs', 'Large Eggs', '12 ct', 1.0, 'piece'],
            ['eggs', 'Large Eggs', '1 dozen', 1.0, 'piece'],
            ['eggs', 'Large Eggs', 'dozen', 1.0, 'piece'],
            ['eggs', 'Large Eggs', '2 dz', 0.5, 'piece'],
            ['cucumber', 'Fresh Cucumber', '1 ea', 12.0, 'piece'],
            ['cucumber', 'Fresh Cucumber', 'each', 12.0, 'piece'],
            ['beans', 'Pinto Beans', 'unknown', null, 'kg'],
            ['beans', 'Pinto Beans', '0 lb', null, 'kg'],
            ['beans', 'Pinto Beans', '12 ct / 4 oz', null, 'kg'],
            ['milk', 'Whole Milk', '16 oz', null, 'l'],
        ];
    }
}
