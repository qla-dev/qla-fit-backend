<?php

namespace Tests\Feature;

use App\Http\Controllers\MealPlanController;
use App\Models\User;
use App\Services\CroatianPrices;
use App\Services\DutchPrices;
use App\Services\SerbianPrices;
use App\Services\ShelfPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Serbian (data.gov.rs) and Dutch (Checkjebon) shelf prices, like the Croatian basket. */
class RegionalPricesTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'KATEGORIJA;NAZIV KATEGORIJE;Naziv proizvoda;Robna marka;Barkod proizvoda;Jedinica mere;Naziv trgovca %s formata;Datum cenovnika;Redovna cena;Cena po jedinici mere;Snižena cena;Datum početka sniženja;Datum kraja sniženja;Stopa PDV;VRSTA_CENOVNIKA';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['fitness.markai.key' => 'test']);
    }

    /** A valid week in which every meal costs 1.25. */
    private function week(): array
    {
        return ['title' => 'Budget week', 'summary' => 'Cheap and simple.', 'days' => array_map(fn ($weekday) => [
            'weekday' => $weekday, 'cost' => 99,
            'meals' => [['slot' => 'lunch', 'name' => 'Pasulj', 'calories' => 600, 'protein' => 30, 'carbs' => 80, 'fat' => 12,
                'ingredients' => [['name' => 'beans', 'quantity' => 200, 'unit' => 'g', 'price' => 0.75, 'staple' => 'beans'],
                    ['name' => 'onions', 'quantity' => 1, 'unit' => 'piece', 'price' => 0.5, 'staple' => null]]]],
        ], range(1, 7))];
    }

    private function csv(array $rows, string $dash = '-', bool $bom = false): string
    {
        return ($bom ? "\u{FEFF}" : '').sprintf(self::HEADER, $dash)."\n".implode("\n", $rows)."\n";
    }

    /**
     * Retailer lists as data.gov.rs serves them. Beans per kg: Lidl's current
     * 299.98 (its October snapshot of 199.98 is history), Maxi's 320.00 in
     * two store formats and a 1 kg pack named in Cyrillic, Gomex's latest
     * 280.00 (a list of snapshots only; October's 100.00 is old). IDEA is
     * down and Univerexport gone.
     */
    private function serbianLists(): array
    {
        $id = SerbianPrices::RETAILERS;
        $url = fn ($code) => SerbianPrices::RESOURCE_URL.$id[$code];

        return [
            $url('lidl') => Http::response($this->csv([
                '6;Mahunarke;Pasulj beli 500g;Golden Sun;4056489000011;kg;Lidl Srbija KD;01-11-2026;149.99;299.98;;;;10;VAZECI_CENOVNIK',
                '6;Mahunarke;Pasulj beli 500g;Golden Sun;4056489000011;kg;Lidl Srbija KD;01-10-2026;99.99;199.98;;;;10;MESECNI_PRESEK',
                '1;Mleko, mlečni i mešoviti proizvodi, jaja;Jaja iz podnog uzgoja S 10/1 MK20 10kom.;Lidl;4056489000028;kom.;Lidl Srbija KD;01-11-2026;164.99;16.50;;;;10;VAZECI_CENOVNIK',
                '21;Lična higijena i kozmetika;Mleko za telo 250ml;Cien;4056489000035;ml;Lidl Srbija KD;01-11-2026;299.99;1199.96;;;;20;VAZECI_CENOVNIK',
            ], '–')),
            $url('maxi') => Http::response($this->csv([
                '6;Mahunarke;Pasulj gradistanac RF 10/1;Maxi;8600000000012;kg;Maxi;01-11-2026;320.00;320.00;;;;10;VAZECI_CENOVNIK',
                '6;Mahunarke;Pasulj gradistanac RF 10/1;Maxi;8600000000012;kg;Mega Maxi;01-11-2026;320.00;320.00;;;;10;VAZECI_CENOVNIK',
                '6;Mahunarke;Пасуљ тетовац 1кг;Maxi;8600000000029;kom;Maxi;01-11-2026;450,00;450,00;;;;10;VAZECI_CENOVNIK',
                '6;Mahunarke;Pasulj sa kobasicom 400g;Carnex;8600000000043;kg;Maxi;01-11-2026;180.00;450.00;;;;10;VAZECI_CENOVNIK',
                '1;Mleko, mlečni i mešoviti proizvodi, jaja;Mleko UHT 2,8% 1l;Imlek;8600000000036;lit;Maxi;01-11-2026;139.99;139.99;129.99;01-11-2026;07-11-2026;10;VAZECI_CENOVNIK',
            ], '-', true)),
            $url('gomex') => Http::response($this->csv([
                '6;Mahunarke;PASULJ BELI 1KG;GOMEX;8600000000050;kg;Gomex doo;01-10-2026;100.00;100.00;;;;10;MESECNI_PRESEK',
                '6;Mahunarke;PASULJ BELI 1KG;GOMEX;8600000000050;kg;Gomex doo;01-11-2026;280.00;280.00;;;;10;MESECNI_PRESEK',
            ])),
            $url('idea') => Http::response('down', 500),
            $url('univerexport') => Http::response('gone', 404),
        ];
    }

    /** Checkjebon's file: AH and Jumbo, with a soft drink "0% suiker" that is not sugar. */
    private function dutchData(): array
    {
        $data = [
            ['n' => 'ah', 'd' => [
                ['n' => 'AH Bruine bonen', 'l' => 'wi1/ah-bruine-bonen', 'p' => 0.99, 's' => '400 g'],
                ['n' => 'AH Halfvolle melk', 'l' => 'wi2/ah-halfvolle-melk', 'p' => 1.19, 's' => '1 l'],
                ['n' => 'AH Scharreleieren M', 'l' => 'wi3/ah-eieren', 'p' => 3.29, 's' => '10 stuks'],
                ['n' => 'Wicky Framboos 0% suiker', 'l' => 'wi4/wicky', 'p' => 1.26, 's' => '1,5 l'],
            ], 'u' => 'https://www.ah.nl/producten/product/', 'c' => 'AH', 'i' => 'https://example.test/ah.svg'],
            ['n' => 'jumbo', 'd' => [
                ['n' => 'Jumbo Bruine Bonen 720 g', 'l' => 'jumbo-bruine-bonen', 'p' => 1.39, 's' => ''],
                ['n' => 'Jumbo Kristalsuiker 1 kg', 'l' => 'jumbo-suiker', 'p' => 0.99, 's' => '1 kg'],
                ['n' => 'Jumbo Eieren {Vrije uitloop} 6 Stuks', 'l' => 'jumbo-eieren', 'p' => 2.19, 's' => ''],
            ], 'u' => 'https://www.jumbo.com/producten/', 'c' => 'Jumbo', 'i' => 'https://example.test/jumbo.ico'],
        ];

        return [
            DutchPrices::DATA_URL => Http::response(json_encode($data, JSON_UNESCAPED_UNICODE)),
            DutchPrices::COMMITS_URL.'*' => Http::response([['sha' => 'abc', 'commit' => ['committer' => ['date' => '2026-10-30T05:34:22Z']]]]),
        ];
    }

    private function fake(array $sources, ?callable $onModel = null): void
    {
        $week = $this->week();
        Http::fake([...$sources,
            'openrouter.ai/*' => function (Request $request) use ($week, $onModel) {
                $onModel && $onModel($request);

                return Http::response(['choices' => [['message' => ['content' => json_encode($week)]]]]);
            },
        ]);
    }

    private function plan(string $region, string $currency)
    {
        Sanctum::actingAs(User::factory()->create(['ai_coins' => 100]));

        return $this->postJson('/api/markai/meal-plans', ['id' => (string) Str::uuid(), 'preferences' => ['diet' => 'any'],
            'region' => $region, 'currency' => $currency, 'language' => 'en']);
    }

    private function sent(string $host): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))->count();
    }

    public function test_every_region_prices_the_same_staples_and_the_app_can_tell_which_are_priced(): void
    {
        $this->assertSame(array_keys(ShelfPrices::REGIONS), MealPlanController::PRICED_REGIONS);
        $this->assertEmpty(array_diff(MealPlanController::PRICED_REGIONS, MealPlanController::REGIONS));
        foreach ([SerbianPrices::class, DutchPrices::class] as $class) {
            $this->assertSame(array_keys(CroatianPrices::STAPLES), array_keys($class::STAPLES), $class);
        }
        $this->assertSame(['cijene', 'datagovrs', 'checkjebon'], array_map(fn ($class) => $class::SOURCE, array_values(ShelfPrices::REGIONS)));
    }

    public function test_serbian_retailer_lists_become_a_basket_in_dinars(): void
    {
        $this->fake($this->serbianLists());
        $this->artisan('prices:refresh', ['region' => 'RS'])->assertSuccessful();
        $prices = app(SerbianPrices::class);
        $staples = $prices->staples();

        $this->assertSame('2026-11-01', $prices->priceDate());
        // Only today's lists count, each listing once however many store
        // formats carry it; the 25th percentile of 280, 299.98, 320 and 450.
        $this->assertSame(['price' => 280.0, 'unit' => 'kg', 'product' => 'PASULJ BELI 1KG', 'chain' => 'gomex',
            'ean' => '8600000000050', 'chains' => ['gomex' => 280.0, 'lidl' => 299.98, 'maxi' => 320.0], 'products' => 4], $staples['beans']);
        // "10/1" is ten eggs on a piece; regular price, not this week's sale;
        // body lotion is filed under hygiene and never prices milk.
        $this->assertSame([16.5, 'piece'], [$staples['eggs']['price'], $staples['eggs']['unit']]);
        $this->assertSame([139.99, 'l', 'Mleko UHT 2,8% 1l'], [$staples['milk']['price'], $staples['milk']['unit'], $staples['milk']['product']]);
        // Each list is downloaded once a day.
        $this->assertSame(count(SerbianPrices::RETAILERS), $this->sent('data.gov.rs'));
        $prices->staples();
        $this->assertSame(count(SerbianPrices::RETAILERS), $this->sent('data.gov.rs'));
        // The chains priced are the vendors a cart is compared across.
        $this->getJson('/api/prices/vendors?region=RS')->assertOk()->assertJsonPath('data.vendors', [
            ['code' => 'gomex', 'name' => 'Gomex'], ['code' => 'lidl', 'name' => 'Lidl'], ['code' => 'maxi', 'name' => 'Maxi'],
        ]);
    }

    public function test_serbian_plans_are_priced_in_dinars_from_the_lists(): void
    {
        $request = null;
        $this->fake($this->serbianLists(), function (Request $sent) use (&$request) {
            $request = $sent;
        });

        $this->plan('RS', 'EUR')->assertOk()
            ->assertJsonPath('data.plan.prices', 'datagovrs')->assertJsonPath('data.plan.currency', 'RSD')
            ->assertJsonPath('data.plan.price_date', '2026-11-01')->assertJsonPath('data.plan.weekly_cost', 8.75)
            ->assertJsonPath('data.plan.days.0.meals.0.ingredients.0.product', 'PASULJ BELI 1KG')
            ->assertJsonPath('data.plan.days.0.meals.0.ingredients.0.ean', '8600000000050');
        $this->assertStringContainsString('Serbian shelf prices in RSD from 2026-11-01', $request['messages'][1]['content']);
        $this->assertStringContainsString('- beans: 280 RSD per kg', $request['messages'][1]['content']);
        $this->assertStringContainsString('Prices and costs are in RSD.', $request['messages'][0]['content']);
    }

    public function test_dutch_plans_are_priced_in_euros_from_checkjebon(): void
    {
        $request = null;
        $this->fake($this->dutchData(), function (Request $sent) use (&$request) {
            $request = $sent;
        });

        $this->plan('NL', 'EUR')->assertOk()
            ->assertJsonPath('data.plan.prices', 'checkjebon')->assertJsonPath('data.plan.currency', 'EUR')
            ->assertJsonPath('data.plan.price_date', '2026-10-30')
            // No barcodes in Checkjebon.
            ->assertJsonPath('data.plan.days.0.meals.0.ingredients.0', ['name' => 'beans', 'quantity' => 200,
                'unit' => 'g', 'price' => 0.75, 'staple' => 'beans', 'product' => 'Jumbo Bruine Bonen 720 g', 'ean' => null]);
        $this->assertStringContainsString('Dutch shelf prices in EUR from 2026-10-30', $request['messages'][1]['content']);

        $staples = app(DutchPrices::class)->staples();
        // Per kg from the listed size or, without one, the name: 1.93 and 2.48.
        $this->assertSame(['price' => 1.93, 'unit' => 'kg', 'chains' => ['ah' => 2.48, 'jumbo' => 1.93]],
            array_intersect_key($staples['beans'], ['price' => 0, 'unit' => 0, 'chains' => 0]));
        // A soft drink "0% suiker" is sold by the litre, so it is not sugar.
        $this->assertSame([0.99, 'kg', 1], [$staples['sugar']['price'], $staples['sugar']['unit'], $staples['sugar']['products']]);
        $this->assertSame([0.33, 'piece'], [$staples['eggs']['price'], $staples['eggs']['unit']]);
        $this->assertSame(1.19, $staples['milk']['price']);
        $this->assertNull($staples['milk']['ean']);
    }

    public function test_a_source_that_is_down_leaves_the_plan_estimated(): void
    {
        $lists = array_map(fn () => Http::response('down', 503), $this->serbianLists());
        $this->fake([...$lists, DutchPrices::DATA_URL => Http::response('down', 502), DutchPrices::COMMITS_URL.'*' => Http::response('down', 502)]);

        $this->plan('RS', 'RSD')->assertOk()->assertJsonPath('data.plan.prices', 'estimate')
            ->assertJsonPath('data.plan.currency', 'RSD')->assertJsonPath('data.plan.price_date', null)
            ->assertJsonMissingPath('data.plan.days.0.meals.0.ingredients.0.staple');
        $this->plan('NL', 'EUR')->assertOk()->assertJsonPath('data.plan.prices', 'estimate')
            ->assertJsonPath('data.plan.currency', 'EUR')->assertJsonPath('data.plan.price_date', null);
        $this->assertSame(count(SerbianPrices::RETAILERS), $this->sent('data.gov.rs'));

        // A source that is down is not asked again by every plan.
        $this->plan('RS', 'RSD')->assertOk()->assertJsonPath('data.plan.prices', 'estimate');
        $this->assertSame(count(SerbianPrices::RETAILERS), $this->sent('data.gov.rs'));
        $this->assertSame([], app(SerbianPrices::class)->vendors());
    }

    public function test_switched_off_sources_are_not_asked(): void
    {
        config(['fitness.prices.datagovrs' => false, 'fitness.prices.checkjebon' => false]);
        $this->fake([...$this->serbianLists(), ...$this->dutchData()]);

        $this->plan('RS', 'RSD')->assertOk()->assertJsonPath('data.plan.prices', 'estimate');
        $this->plan('NL', 'EUR')->assertOk()->assertJsonPath('data.plan.prices', 'estimate');
        $this->assertSame(0, $this->sent('data.gov.rs') + $this->sent('githubusercontent'));
    }
}
