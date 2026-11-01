<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** A daily basket at one Kroger-family store near the configured US ZIP code. */
class KrogerPrices extends ShelfPrices
{
    public const SOURCE = 'kroger';

    public const CURRENCY = 'USD';

    public const COUNTRY = 'US (Kroger)';

    public const TOKEN_URL = 'https://api.kroger.com/v1/connect/oauth2/token';

    public const LOCATIONS_URL = 'https://api.kroger.com/v1/locations';

    public const PRODUCTS_URL = 'https://api.kroger.com/v1/products';

    protected const LOCK_SECONDS = 180;

    /** [search term, whole-word matches, exclusions, allowed units]. */
    public const STAPLES = [
        'chicken breast' => ['chicken breast', ['chicken breast', 'chicken breasts'], ['bone-in', 'bone in', 'cooked', 'grilled', 'breaded', 'nugget', 'strip', 'deli', 'smoked', 'canned', 'stuffed', 'seasoned', 'marinated'], ['kg']],
        'minced beef' => ['ground beef', ['ground beef'], ['burger', 'patty', 'patties', 'seasoned', 'cooked', 'plant'], ['kg']],
        'pork loin' => ['pork loin', ['pork loin', 'pork tenderloin'], ['bone-in', 'bone in', 'smoked', 'cooked', 'breaded', 'seasoned', 'marinated'], ['kg']],
        'eggs' => ['eggs', ['eggs'], ['liquid', 'whites', 'boiled', 'chocolate', 'candy', 'substitute', 'bites'], ['piece']],
        'milk' => ['milk', ['milk'], ['chocolate', 'almond', 'oat', 'soy', 'coconut', 'rice', 'powder', 'dry milk', 'condensed', 'evaporated', 'flavored', 'strawberry', 'vanilla', 'buttermilk', 'goat', 'shake'], ['l']],
        'yogurt' => ['plain yogurt', ['plain yogurt', 'plain greek yogurt', 'greek plain yogurt'], ['drink', 'almond', 'coconut', 'oat', 'soy', 'frozen'], ['kg']],
        'cottage cheese' => ['cottage cheese', ['cottage cheese'], ['pineapple', 'fruit', 'chive'], ['kg']],
        'cheese' => ['cheddar cheese block', ['cheddar cheese', 'gouda cheese'], ['shredded', 'slice', 'cracker', 'sauce', 'dip', 'snack', 'spread', 'macaroni', 'puff', 'stick'], ['kg']],
        'butter' => ['butter', ['butter'], ['peanut', 'almond', 'cashew', 'cocoa', 'shea', 'plant', 'margarine', 'spray', 'cookie', 'cinnamon', 'garlic', 'spread'], ['kg']],
        'olive oil' => ['olive oil', ['olive oil'], ['spray', 'soap', 'tuna', 'sardine', 'dressing', 'infused', 'garlic', 'lemon', 'rosemary'], ['l']],
        'rice' => ['long grain rice', ['rice'], ['ready', 'microwave', 'seasoned', 'mix', 'pudding', 'cake', 'cracker', 'flour', 'noodle', 'milk', 'vinegar', 'cooked', 'fried'], ['kg']],
        'pasta' => ['spaghetti pasta', ['spaghetti', 'penne', 'macaroni', 'fusilli', 'pasta', 'linguine'], ['sauce', 'meal', 'salad', 'cheese', 'ready', 'cooked', 'ravioli', 'tortellini', 'chicken', 'beef', 'squash'], ['kg']],
        'oats' => ['old fashioned oats', ['oats', 'oatmeal'], ['cookie', 'bar', 'instant', 'flavored', 'cereal', 'granola', 'drink'], ['kg']],
        'bread' => ['sandwich bread', ['bread'], ['crumb', 'garlic', 'mix', 'banana', 'pumpkin', 'sweet', 'cinnamon', 'raisin', 'pudding'], ['kg']],
        'flour' => ['all purpose flour', ['flour'], ['almond', 'coconut', 'rice', 'corn', 'tortilla', 'mix', 'gluten free'], ['kg']],
        'potatoes' => ['russet potatoes', ['potato', 'potatoes'], ['chip', 'mashed', 'fries', 'frozen', 'canned', 'sweet', 'salad', 'shredded'], ['kg', 'piece']],
        'onions' => ['yellow onions', ['onion', 'onions'], ['powder', 'dried', 'fried', 'dip', 'ring', 'diced', 'chopped', 'frozen', 'pickled'], ['kg', 'piece']],
        'garlic' => ['fresh garlic', ['garlic'], ['powder', 'salt', 'minced', 'chopped', 'paste', 'bread', 'butter', 'sauce', 'seasoning', 'pickled', 'peeled'], ['kg', 'piece']],
        'tomatoes' => ['fresh tomatoes', ['tomato', 'tomatoes'], ['canned', 'diced', 'paste', 'sauce', 'crushed', 'peeled', 'stewed', 'dried', 'juice', 'soup', 'salsa'], ['kg', 'piece']],
        'peppers' => ['bell peppers', ['bell pepper', 'bell peppers'], ['stuffed', 'frozen', 'sliced', 'diced', 'pickled', 'roasted'], ['kg', 'piece']],
        'cucumber' => ['cucumber', ['cucumber', 'cucumbers'], ['pickle', 'salad', 'dressing', 'water', 'drink'], ['kg', 'piece']],
        'carrots' => ['carrots', ['carrot', 'carrots'], ['juice', 'cake', 'canned', 'frozen', 'glazed', 'shredded', 'peas', 'mixed'], ['kg', 'piece']],
        'cabbage' => ['green cabbage', ['cabbage'], ['slaw', 'shredded', 'kimchi', 'sauerkraut', 'pickled', 'red'], ['kg', 'piece']],
        'lettuce' => ['romaine lettuce', ['lettuce', 'romaine'], ['salad', 'kit', 'dressing', 'shredded'], ['kg', 'piece']],
        'spinach' => ['fresh spinach', ['spinach'], ['frozen', 'canned', 'creamed', 'dip', 'pasta', 'ravioli', 'pizza'], ['kg']],
        'apples' => ['gala apples', ['apple', 'apples'], ['juice', 'sauce', 'pie', 'dried', 'chip', 'cider', 'sliced', 'caramel'], ['kg', 'piece']],
        'bananas' => ['bananas', ['banana', 'bananas'], ['chip', 'dried', 'bread', 'pudding', 'frozen', 'smoothie'], ['kg', 'piece']],
        'lemons' => ['fresh lemons', ['lemon', 'lemons'], ['juice', 'oil', 'pepper', 'tea', 'cake', 'drink', 'water', 'extract'], ['kg', 'piece']],
        'beans' => ['pinto beans', ['pinto beans', 'kidney beans', 'black beans', 'white beans'], ['refried', 'chili', 'baked', 'soup', 'seasoned', 'sauce'], ['kg']],
        'lentils' => ['lentils', ['lentil', 'lentils'], ['soup', 'pasta', 'chip', 'meal', 'curry'], ['kg']],
        'chickpeas' => ['chickpeas', ['chickpea', 'chickpeas', 'garbanzo beans'], ['flour', 'pasta', 'snack', 'roasted', 'hummus', 'salad'], ['kg']],
        'tuna' => ['canned tuna', ['tuna'], ['salad', 'kit', 'dinner', 'steak', 'fresh', 'frozen', 'fillet', 'cat'], ['kg']],
        'sardines' => ['sardines', ['sardine', 'sardines'], ['cat', 'paste', 'spread'], ['kg']],
        'hake' => ['hake', ['hake'], ['breaded', 'battered', 'cooked', 'seasoned'], ['kg']],
        'canned tomatoes' => ['canned diced tomatoes', ['diced tomatoes', 'peeled tomatoes', 'crushed tomatoes', 'stewed tomatoes'], ['sauce', 'soup', 'chili', 'roasted', 'seasoned', 'jalape', 'basil', 'garlic', 'onion', 'pepper'], ['kg']],
        'frozen vegetables' => ['frozen mixed vegetables', ['mixed vegetables', 'vegetable blend', 'vegetable medley'], ['sauce', 'seasoned', 'canned', 'soup', 'rice', 'pasta'], ['kg']],
        'walnuts' => ['walnuts', ['walnut', 'walnuts'], ['candied', 'glazed', 'chocolate', 'mix', 'oil', 'cookie'], ['kg']],
        'honey' => ['honey', ['honey'], ['roasted', 'mustard', 'cereal', 'ham', 'sauce', 'tea', 'cough', 'cookie', 'cracker', 'bun', 'nut', 'butter'], ['kg']],
        'sugar' => ['granulated sugar', ['granulated sugar', 'white sugar', 'pure cane sugar'], ['brown', 'powdered', 'substitute', 'free', 'blend', 'packet', 'cube'], ['kg']],
        'coffee' => ['ground coffee', ['ground coffee', 'coffee beans', 'whole bean coffee'], ['instant', 'pod', 'capsule', 'cold brew', 'decaf', 'vanilla', 'caramel', 'hazelnut'], ['kg']],
    ];

    private const EXCLUDE = ['body', 'lotion', 'soap', 'shampoo', 'conditioner', 'candle', 'candy', 'chocolate',
        'dog', 'cat food', 'pet food', 'treats', 'supplement', 'capsule', 'scent', 'cleaner', 'detergent'];

    private const EXCLUDED_CATEGORIES = ['beauty', 'personal care', 'health', 'pet', 'cleaning', 'household'];

    private const FRESH = ['potatoes', 'onions', 'garlic', 'tomatoes', 'peppers', 'cucumber', 'carrots',
        'cabbage', 'lettuce', 'spinach', 'apples', 'bananas', 'lemons'];

    public function enabled(): bool
    {
        return (bool) config('fitness.prices.kroger.client_id') && (bool) config('fitness.prices.kroger.client_secret');
    }

    public function staples(): array
    {
        return $this->enabled() ? parent::staples() : [];
    }

    public function priceDate(): ?string
    {
        return $this->enabled() ? parent::priceDate() : null;
    }

    /** A ZIP change must never reuse another store's basket or retry lock. */
    protected function key(string $name): string
    {
        return parent::key((string) config('fitness.prices.kroger.zip', '45202').':'.$name);
    }

    protected function fetch(): array
    {
        $token = $this->token();
        $location = $this->location($token);
        $chain = Str::slug($location['chain'] ?? $location['name'] ?? 'Kroger');
        $found = [];
        // Small batches limit concurrent requests and keep a cold plan responsive.
        foreach (array_chunk(array_keys(self::STAPLES), 5) as $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($staple) => $pool->as($staple)->acceptJson()->withToken($token)->connectTimeout(5)->timeout(15)
                    ->get(self::PRODUCTS_URL, ['filter.term' => self::STAPLES[$staple][0],
                        'filter.locationId' => $location['locationId'], 'filter.limit' => 50]),
                $batch
            ));
            foreach ($responses as $staple => $response) {
                // An incomplete refresh must not replace a complete older basket.
                if (! $response instanceof Response || ! $response->successful()) {
                    if ($response instanceof Response && $response->status() === 401) {
                        Cache::forget($this->tokenKey());
                    }
                    throw new RuntimeException('Kroger product search unavailable.');
                }
                $found[$staple] = $this->candidates($staple, $response->json('data', []) ?? [], $chain);
            }
        }

        return $found;
    }

    private function tokenKey(): string
    {
        return self::SOURCE.':token:'.hash('sha256', (string) config('fitness.prices.kroger.client_id'));
    }

    private function token(): string
    {
        if ($token = Cache::get($this->tokenKey())) {
            return $token;
        }
        $response = Http::acceptJson()->asForm()->withBasicAuth(
            config('fitness.prices.kroger.client_id'), config('fitness.prices.kroger.client_secret')
        )->connectTimeout(5)->timeout(15)->post(self::TOKEN_URL, ['grant_type' => 'client_credentials', 'scope' => 'product.compact']);
        $token = $response->json('access_token');
        $expires = (int) $response->json('expires_in', 0);
        // Do not put OAuth response bodies or credentials in exception logs.
        if (! $response->successful() || ! is_string($token) || $token === '' || $expires <= 0) {
            throw new RuntimeException('Kroger authentication unavailable (HTTP '.$response->status().').');
        }
        Cache::put($this->tokenKey(), $token, max(1, $expires - min(60, (int) ceil($expires / 10))));

        return $token;
    }

    private function location(string $token): array
    {
        $cached = Cache::get($this->key('location'));
        if (($cached['fetched'] ?? null) === now()->toDateString()) {
            return $cached['data'];
        }
        $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(15)
            ->get(self::LOCATIONS_URL, ['filter.zipCode.near' => config('fitness.prices.kroger.zip', '45202'), 'filter.limit' => 1]);
        $location = $response->json('data.0');
        if (! $response->successful() || empty($location['locationId'])) {
            if ($response->status() === 401) {
                Cache::forget($this->tokenKey());
            }
            throw new RuntimeException('Kroger location unavailable (HTTP '.$response->status().').');
        }
        Cache::forever($this->key('location'), ['fetched' => now()->toDateString(), 'data' => $location]);

        return $location;
    }

    private function candidates(string $staple, array $products, string $chain): array
    {
        [, $terms, $exclusions, $units] = self::STAPLES[$staple];
        $items = [];
        foreach ($products as $product) {
            $name = $this->normalize($product['description'] ?? '');
            $categories = $this->normalize(implode(' ', $product['categories'] ?? []));
            $listing = $product['items'][0] ?? [];
            $price = $listing['price']['regular'] ?? null;
            $matches = array_filter($terms, fn ($term) => preg_match('/(?<![\p{L}])'.preg_quote($term, '/').'(?![\p{L}])/u', $name));
            if (! is_numeric($price) || $price <= 0 || ! $matches
                || $this->excluded($name, [...self::EXCLUDE, ...$exclusions])
                || $this->excluded($categories, self::EXCLUDED_CATEGORIES)) {
                continue;
            }
            // Whole-word matches alone still admit garlic bologna and canned carrots.
            if (in_array($staple, self::FRESH, true) && ! str_contains($categories, 'produce')) {
                continue;
            }
            // The same "mixed vegetables" description also appears on cans.
            if ($staple === 'frozen vegetables'
                && $this->normalize($product['temperature']['indicator'] ?? '') !== 'frozen'
                && ! str_contains($categories, 'frozen')
                && ! str_contains($name, 'frozen')) {
                continue;
            }
            $size = $this->size($listing);
            if (! $size || ! in_array($size[1], $units, true)) {
                continue;
            }
            [$amount, $unit] = $size;
            $items[] = ['price' => round($price / $amount, 2), 'unit' => $unit,
                'product' => $product['description'], 'chain' => $chain, 'ean' => $product['upc'] ?? null,
                'date' => now()->toDateString()];
        }

        return $items;
    }

    /** US weight/volume units; unknown or ambiguous sizes never become one piece. */
    private function size(array $listing): ?array
    {
        // Kroger's by-weight regular price is per pound, regardless of estimated pack weight.
        if ($this->normalize($listing['soldBy'] ?? '') === 'weight') {
            return [0.45359237, 'kg'];
        }
        $text = $this->normalize($listing['size'] ?? '');
        $text = str_replace(['fl. oz.', 'fl. oz', 'fl oz.', 'fluid ounces', 'fluid ounce'], 'fl oz', $text);
        // Half-gallon milk is commonly written "1/2 gal" by the API.
        $text = preg_replace_callback('/^(?:(\d+)\s+)?(\d+)\/(\d+)(?=\s*[a-z])/', function ($match) {
            return (int) $match[3] > 0
                ? (string) ((int) ($match[1] ?? 0) + (int) $match[2] / (int) $match[3])
                : 'invalid';
        }, $text);
        if (in_array($text, ['each', 'ea'], true)) {
            return [1, 'piece'];
        }
        if (in_array($text, ['dozen', 'dz'], true)) {
            return [12, 'piece'];
        }
        $units = 'fl oz|lbs?|pounds?|oz|ounces?|gal(?:lons?)?|qt|quarts?|pt|pints?|ct|count|ea|each|dozen|dz|kg|g|ml|l';
        if (! preg_match('/^(?:(\d+)\s*x\s*)?(\d*\.?\d+)\s*('.$units.')\.?$/u', $text, $match)) {
            return null;
        }
        $amount = (float) $match[2] * (($match[1] ?? '') !== '' ? (int) $match[1] : 1);
        [$scale, $unit] = match ($match[3]) {
            'lb', 'lbs', 'pound', 'pounds' => [0.45359237, 'kg'],
            'oz', 'ounce', 'ounces' => [0.028349523125, 'kg'],
            'fl oz' => [0.0295735295625, 'l'],
            'gal', 'gallon', 'gallons' => [3.785411784, 'l'],
            'qt', 'quart', 'quarts' => [0.946352946, 'l'],
            'pt', 'pint', 'pints' => [0.473176473, 'l'],
            'dozen', 'dz' => [12, 'piece'],
            'ct', 'count', 'ea', 'each' => [1, 'piece'],
            'kg' => [1, 'kg'], 'g' => [0.001, 'kg'], 'l' => [1, 'l'], 'ml' => [0.001, 'l'],
        };

        return $amount > 0 ? [$amount * $scale, $unit] : null;
    }
}
