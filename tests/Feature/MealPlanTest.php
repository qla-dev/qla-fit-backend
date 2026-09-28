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
use ZipArchive;

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

    /**
     * A two-chain archive in cijene.dev's layout. Beans per kg across both
     * chains: a 0.10 clearance line, then 2.00, 3.00, 4.00 and 5.00; potatoes by kg;
     * body lotion named like milk; an air freshener named like apples.
     */
    private function archive(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cijene').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('konzum/products.csv', "product_id,barcode,name,brand,category,unit,quantity\n"
            ."1,1,GRAH BIJELI 500 g,A,HRANA,ko,0.50 kg\n"
            ."2,2,GRAH CRVENI 400 g,B,HRANA,ko,0.40 kg\n"
            ."3,3,MLIJEKO ZA TIJELO 250 ml,Nivea,Kozmetika,L,0.25\n"
            ."4,4,SPREJ JABUKA CIMET 300ml,Glade,HRANA,ko,0.30 l\n"
            ."5,5,KRUMPIR,,HRANA,kg,1.00 kg\n"
            ."6,6,GRAH SALATA 300 g,C,HRANA,ko,0.30 kg\n");
        $zip->addFromString('konzum/prices.csv', "store_id,product_id,price,unit_price,best_price_30,anchor_price,special_price\n"
            ."a,1,1.00,2.00,,,\nb,1,1.00,2.00,,,\n"
            ."a,2,1.20,3.00,,,\n"
            ."a,3,5.00,20.00,,,\n"
            ."a,4,2.00,6.00,,,\n"
            ."a,5,0.89,0.89,,,\nb,5,0.91,0.91,,,\n"
            ."a,6,0.30,1.00,,,\n");
        $zip->addFromString('lidl/products.csv', "product_id,barcode,name,brand,category,unit,quantity\n"
            ."9,9,Grah smeđi,D,Hrana,1kg,1\n"
            ."10,10,Grah rasprodaja,E,Hrana,1kg,1\n"
            ."11,11,Grah pinto,F,Hrana,1kg,1\n");
        $zip->addFromString('lidl/prices.csv', "store_id,product_id,price,unit_price,best_price_30,anchor_price,special_price\n"
            ."x,9,4.00,4.00,,,\n"
            ."x,10,0.10,0.10,,,\n"
            ."x,11,5.00,5.00,,,\n");
        $zip->close();

        return $path;
    }

    private function fake(array $plan, ?callable $onModel = null, string $date = '2026-09-28'): void
    {
        config(['fitness.markai.key' => 'test']);
        $archive = file_get_contents($this->archive());
        Http::fake([
            'api.cijene.dev/v0/list' => Http::response(['archives' => [
                ['date' => $date, 'url' => "https://api.cijene.dev/v0/archive/{$date}.zip"],
                ['date' => '2026-09-01', 'url' => 'https://api.cijene.dev/v0/archive/2026-09-01.zip'],
            ]]),
            'api.cijene.dev/v0/archive/*' => Http::response($archive),
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

    public function test_the_daily_archive_becomes_a_basket_of_real_staple_prices(): void
    {
        $this->fake($this->week());
        $this->artisan('prices:refresh')->assertSuccessful();
        $prices = app(CroatianPrices::class);

        $this->assertSame('2026-09-28', $prices->priceDate());
        $staples = $prices->staples();
        // Products are compared per kg across chains (Lidl writes '1kg' in the
        // unit column); the 25th percentile of 0.10, 2.00, 3.00, 4.00, 5.00 is
        // 2.00, not the clearance line. 'GRAH SALATA' (bean salad) is excluded.
        $this->assertSame(2.0, $staples['beans']['price']);
        $this->assertSame('kg', $staples['beans']['unit']);
        $this->assertSame('GRAH BIJELI 500 g', $staples['beans']['product']);
        $this->assertSame(0.9, $staples['potatoes']['price']);
        // Body lotion and air freshener never price food.
        $this->assertArrayNotHasKey('milk', $staples);
        $this->assertArrayNotHasKey('apples', $staples);

        // Nothing newer: a second run downloads nothing.
        $this->artisan('prices:refresh')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_croatian_plans_are_priced_from_the_basket(): void
    {
        $this->member();
        $prompt = null;
        $this->fake($this->week(), function (Request $request) use (&$prompt) {
            $prompt = $request['messages'][1]['content'];
        });
        $this->artisan('prices:refresh');

        $response = $this->postJson('/api/markai/meal-plans', $this->body())->assertOk()
            ->assertJsonPath('data.plan.prices', 'cijene')->assertJsonPath('data.plan.currency', 'EUR')
            ->assertJsonPath('data.plan.price_date', '2026-09-28')->assertJsonPath('data.ai_coins', 97);

        $this->assertStringContainsString('- beans: 2 EUR per kg', $prompt);
        // A day's cost is what its ingredients add up to, not the model's figure.
        $response->assertJsonPath('data.plan.days.0.cost', 1.25)->assertJsonPath('data.plan.weekly_cost', 8.75);
    }

    public function test_the_first_plan_is_estimated_and_starts_the_archive_in_the_background(): void
    {
        $this->member();
        $this->fake($this->week());

        $this->postJson('/api/markai/meal-plans', $this->body())->assertOk()
            ->assertJsonPath('data.plan.prices', 'estimate')->assertJsonPath('data.plan.price_date', null);
        // Processed after the response; the next plan is priced from it.
        $this->app->terminate();
        $this->assertSame('2026-09-28', app(CroatianPrices::class)->priceDate());
        $this->postJson('/api/markai/meal-plans', $this->body())->assertOk()
            ->assertJsonPath('data.plan.prices', 'cijene');
    }

    public function test_a_failed_archive_keeps_the_last_good_prices(): void
    {
        $this->fake($this->week());
        $this->artisan('prices:refresh');
        config(['fitness.markai.key' => 'test']);
        Http::fake([
            'api.cijene.dev/v0/list' => Http::response(['archives' => [
                ['date' => '2026-09-29', 'url' => 'https://api.cijene.dev/v0/archive/2026-09-29.zip']]]),
            'api.cijene.dev/v0/archive/*' => Http::response('not a zip'),
        ]);
        cache()->forget('cijene:latest-archive');

        $this->artisan('prices:refresh');
        $this->assertSame('2026-09-28', app(CroatianPrices::class)->priceDate());
        $this->assertSame(2.0, app(CroatianPrices::class)->staples()['beans']['price']);
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
