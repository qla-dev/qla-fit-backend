<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Current Croatian grocery prices from api.cijene.dev.
 *
 * The key stays on the server (the terms forbid sharing it), and every answer
 * is cached for the day: the free plan allows a limited number of requests,
 * and the chains publish prices once a day anyway.
 */
class CroatianPrices
{
    /**
     * The basket meal plans are priced from: everyday Croatian staples, each
     * searched once a day. Croatian names, because that is how the chains
     * list them; the English name is what the plan and the grocery list show.
     */
    public const STAPLES = [
        'chicken breast' => 'pileća prsa', 'minced beef' => 'mljeveno juneće meso',
        'pork loin' => 'svinjski kare', 'eggs' => 'jaja', 'milk' => 'mlijeko',
        'yogurt' => 'jogurt', 'cottage cheese' => 'svježi sir', 'cheese' => 'gauda',
        'butter' => 'maslac', 'olive oil' => 'maslinovo ulje', 'rice' => 'riža',
        'pasta' => 'tjestenina', 'oats' => 'zobene pahuljice', 'bread' => 'kruh',
        'flour' => 'brašno', 'potatoes' => 'krumpir', 'onions' => 'luk',
        'garlic' => 'češnjak', 'tomatoes' => 'rajčica', 'peppers' => 'paprika',
        'cucumber' => 'krastavac', 'carrots' => 'mrkva', 'cabbage' => 'kupus',
        'lettuce' => 'zelena salata', 'spinach' => 'špinat', 'apples' => 'jabuke',
        'bananas' => 'banane', 'lemons' => 'limun', 'beans' => 'grah',
        'lentils' => 'leća', 'chickpeas' => 'slanutak', 'tuna' => 'tuna',
        'sardines' => 'srdele', 'hake' => 'oslić', 'canned tomatoes' => 'pelati',
        'frozen vegetables' => 'smrznuto povrće', 'walnuts' => 'orasi',
        'honey' => 'med', 'sugar' => 'šećer', 'coffee' => 'kava',
    ];

    public function configured(): bool
    {
        return (bool) config('fitness.cijene.key');
    }

    /**
     * Cheapest average price per staple across the chains, in EUR, as
     * [english name => ['price' => float, 'unit' => string, 'product' => string, 'chain' => ?string]].
     * A staple that fails or finds nothing is left out rather than guessed.
     * The whole basket is one parallel round of requests, once a day.
     */
    public function staples(): array
    {
        return Cache::remember('cijene:staples:'.now()->toDateString(), now()->endOfDay(), function () {
            $names = array_keys(self::STAPLES);
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($english) => $pool->as($english)->withToken(config('fitness.cijene.key'))->timeout(15)
                    ->get(config('fitness.cijene.url').'/v1/products/', ['q' => self::STAPLES[$english], 'limit' => 10]),
                $names
            ));
            $prices = [];
            foreach ($names as $english) {
                $response = $responses[$english] ?? null;
                if ($response instanceof Response && $response->ok()) {
                    $best = $this->cheapestOf($response->json('products', []), $english);
                    if ($best) {
                        $prices[$english] = $best;
                    }
                }
            }

            return $prices;
        });
    }

    /** The lowest per-chain average price for one search, or null; misses are cached for an hour. */
    public function cheapest(string $query): ?array
    {
        $key = 'cijene:search:'.md5($query).':'.now()->toDateString();
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached['hit'] ?? null;
        }
        try {
            $response = Http::withToken(config('fitness.cijene.key'))->timeout(15)
                ->get(config('fitness.cijene.url').'/v1/products/', ['q' => $query, 'limit' => 10]);
            $best = $response->ok() ? $this->cheapestOf($response->json('products', []), $query) : null;
        } catch (Throwable $error) {
            report($error);
            $best = null;
        }
        Cache::put($key, ['hit' => $best], $best ? now()->endOfDay() : now()->addHour());

        return $best;
    }

    private function cheapestOf(array $products, string $fallbackName): ?array
    {
        $best = null;
        foreach ($products as $product) {
            foreach ($product['chains'] ?? [] as $chain) {
                $price = (float) ($chain['avg_price'] ?? 0);
                if ($price > 0 && ($best === null || $price < $best['price'])) {
                    $best = ['price' => round($price, 2),
                        'unit' => trim(($product['quantity'] ?? '').' '.($product['unit'] ?? '')),
                        'product' => $product['name'] ?? $fallbackName, 'chain' => $chain['chain'] ?? null];
                }
            }
        }

        return $best;
    }
}
