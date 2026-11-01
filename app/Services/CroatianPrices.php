<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Croatian grocery prices from the cijene.dev API.
 *
 * Chains must publish their shelf prices by law; cijene.dev collects them and
 * answers a product search with each chain's average price for the day. Once
 * a day the staples below are searched in parallel (a second or two) and kept
 * as a small basket that meal plans are priced from. Without CIJENE_API_KEY
 * there is no basket and plans are estimated.
 */
class CroatianPrices extends ShelfPrices
{
    public const SOURCE = 'cijene';

    public const CURRENCY = 'EUR';

    public const COUNTRY = 'Croatian';

    public const SEARCH_URL = 'https://api.cijene.dev/v1/products/';

    public const CHAINS_URL = 'https://api.cijene.dev/v1/chains/';

    /** The chain list: ['fetched' => Y-m-d, 'codes' => [code, …]]. */
    private const VENDORS_KEY = 'cijene:vendors';

    /**
     * The basket meal plans are priced from: [english => [prefix, exclusions]].
     * Chains name products type-first ("MLIJEKO UHT 2,8% 1L", "KRUMPIR
     * MLADI"), so a chain's product counts only when its name starts with the
     * prefix as a whole word (diacritics folded); a trailing * makes it a
     * stem, for "jabuka"/"jabuke". The English name is what plans show.
     */
    public const STAPLES = [
        'chicken breast' => ['pileca prsa', ['narezak', 'pan', 'pecen', 'dimlj']],
        'minced beef' => ['mljeveno junece', ['svinj']],
        'pork loin' => ['svinjski kare', ['pan', 'dimlj', 'pecen']],
        'eggs' => ['jaja', ['cokolad', 'naljep', 'boja']],
        'milk' => ['mlijeko', ['cokolad', 'kokos', 'zob', 'bad', 'soj', 'rizin', 'u prahu', 'kondenz']],
        'yogurt' => ['jogurt', ['smoothie', 'voc', 'grcki', 'pit']],
        'cottage cheese' => ['svjezi sir', ['namaz', 'krem']],
        'cheese' => ['sir gauda', ['listic', 'narib']],
        'butter' => ['maslac', ['kikiriki', 'kakao', 'bilj']],
        'olive oil' => ['maslinovo ulje', []],
        'rice' => ['riza', ['kapsul', 'krek', 'napit', 'mlijek']],
        'pasta' => ['tjestenina', ['gotov', 'salat', 'sa ', 'sir', 'umak']],
        'oats' => ['zobene pahuljice', ['cokolad', 'med']],
        'bread' => ['kruh', ['mrvice', 'prepec', 'tost', 'dvopek']],
        'flour' => ['brasno', ['krmno', 'kukuruzn']],
        'potatoes' => ['krumpir', ['cips', 'pire', 'krokete', 'pom', 'smrz']],
        'onions' => ['luk', ['cesnjak', 'prah', 'pasta', 'suseni', 'prziv', 'kockic', 'smrz', 'ledo']],
        'garlic' => ['cesnjak', ['kaps', 'prah', 'granul']],
        'tomatoes' => ['rajcica', ['pasir', 'koncentr', 'susen', 'umak', 'sok', 'kecap', 'pelat', 'konzerv', 'sjeck', 'pire']],
        'peppers' => ['paprika', ['mljeven', 'slatka mlj', 'ljuta mlj', 'pasta', 'ajvar', 'cips', 'prah', 'pecen', 'ocij', 'kisel', 'punjen']],
        'cucumber' => ['krastav*', ['kiseli', 'salata', 'ocij', 'oc.', 'kornison']],
        'carrots' => ['mrkva', ['sok', 'salata', 'smrz', 'kock']],
        'cabbage' => ['kupus', ['kis', 'salata', 'list']],
        'lettuce' => ['salata', ['od ', 'tun', 'krumpir', 'mix', 'kupus', 'rikul', 'grah']],
        'spinach' => ['spinat', ['krem', 'smrz']],
        'apples' => ['jabuk*', ['sok', 'cips', 'susen', 'kasa', 'ocat', 'pita']],
        'bananas' => ['banan*', ['cips', 'susen']],
        'lemons' => ['limun', ['sok', 'trava', 'kora', 'ulje', 'secer', 'caj', 'aroma']],
        'beans' => ['grah', ['mahun', 'salata', 'varivo']],
        'lentils' => ['leca', []],
        'chickpeas' => ['slanutak', ['humus', 'namaz']],
        'tuna' => ['tuna', ['pesto', 'salata', 'namaz', 'pasteta']],
        'sardines' => ['srdel*', ['pasteta', 'namaz', 'pecen', 'prilog', 'porcij']],
        'hake' => ['oslic', ['glava', 'rep', 'pohan', 'stapic']],
        'canned tomatoes' => ['pelati', []],
        'frozen vegetables' => ['smrznuto povrce', []],
        'walnuts' => ['orasi', ['cokolad', 'u medu', 'med ']],
        'honey' => ['med ', ['maramic', 'bombon', 'sapun', 'krem']],
        'sugar' => ['secer', ['ugostit', 'vanil', 'u prahu', 'bez', 'kocke', 'mljev', 'smed']],
        'coffee' => ['kava', ['kaps', 'jastuc', 'instant', '3u1', '2u1', 'napit', 'hlad']],
    ];

    /**
     * What to search for a stem: the API matches whole words, so "jabuk"
     * finds nothing while "jabuke" finds every apple.
     */
    private const QUERIES = ['krastav*' => 'krastavci', 'jabuk*' => 'jabuke', 'banan*' => 'banane', 'srdel*' => 'srdele'];

    /**
     * Fresh produce, with the search that reaches it: [english => query].
     * A plain "limun" or "rajcica" search is all syrups and tins, so these
     * are searched the way loose produce is listed, and only listings sold
     * loose (per kg or per piece, no pack weight in the name) count.
     */
    private const FRESH = [
        'lemons' => 'limun kg', 'tomatoes' => 'rajcica kg', 'onions' => 'luk zuti', 'cucumber' => 'krastavac',
        'carrots' => 'mrkva kg', 'cabbage' => 'kupus kg', 'apples' => 'jabuke', 'bananas' => 'banane',
        'lettuce' => 'salata kristal', 'garlic' => 'cesnjak', 'peppers' => 'paprika babura',
    ];

    /** A pack weight or volume in a name: "800g", "1,5 L". */
    private const PACK_SIZE = '/\d\s*(kg|dkg|gr|g|l|dl|cl|ml)\b/u';

    /** Categories that are never food ("Mlijeko za tijelo" is body lotion). */
    private const EXCLUDED_CATEGORIES = ['kozmet', 'kucanst', 'drogerij', 'higijen', 'ljubim', 'njega'];

    /** Only real shelf products: never priced as food, whatever the name. */
    private const EXCLUDE = ['sprej', 'maramic', 'naljepnic', 'sampon', 'sapun', 'deterd',
        'hrana za', 'za pse', 'za macke', 'kozmet', 'aroma', 'svijec', 'miris', 'snizen'];

    /**
     * Every chain cijene.dev collects prices from, as [code, name], asked
     * once a day; the list is the API's, never a fixed one. [] without a key
     * or when the API is down and nothing was fetched before.
     */
    public function vendors(): array
    {
        $key = config('fitness.cijene.key');
        if (! $key) {
            return [];
        }
        $cached = Cache::get(self::VENDORS_KEY);
        if (($cached['fetched'] ?? null) !== now()->toDateString()) {
            try {
                $codes = Http::withToken($key)->timeout(10)->get(self::CHAINS_URL)->throw()->json('chains', []);
                if (is_array($codes) && $codes !== []) {
                    $cached = ['fetched' => now()->toDateString(), 'codes' => array_values(array_filter($codes, 'is_string'))];
                    Cache::forever(self::VENDORS_KEY, $cached);
                }
            } catch (Throwable $error) {
                report($error);
            }
        }

        return array_map(fn ($code) => ['code' => $code, 'name' => $this->vendorName($code)], $cached['codes'] ?? []);
    }

    public function enabled(): bool
    {
        return (bool) config('fitness.cijene.key');
    }

    /** Every staple searched in parallel, a second or two. */
    protected function fetch(): array
    {
        $key = config('fitness.cijene.key');
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($staple) => $pool->as($staple)->withToken($key)->timeout(10)
                ->get(self::SEARCH_URL, ['q' => $this->query($staple), 'limit' => 100]),
            array_keys(self::STAPLES)
        ));
        $found = [];
        foreach ($responses as $staple => $response) {
            if ($response instanceof Response && $response->successful()) {
                $found[$staple] = $this->candidates($staple, $response->json('products', []) ?? []);
            }
        }

        return $found;
    }

    private function query(string $staple): string
    {
        $prefix = self::STAPLES[$staple][0];

        return self::FRESH[$staple] ?? self::QUERIES[$prefix] ?? trim($prefix);
    }

    /**
     * Every chain's listing of a matching product, as a price per kg, l or
     * piece. Each chain names and sizes the product itself, so each listing
     * is judged on its own.
     */
    private function candidates(string $staple, array $products): array
    {
        [$prefix, $exclusions] = self::STAPLES[$staple];
        $prefix = $this->normalize($prefix);
        $exclusions = array_map(fn ($word) => $this->normalize($word), $exclusions);
        $items = [];
        foreach ($products as $product) {
            foreach ($product['chains'] ?? [] as $listing) {
                $name = $this->normalize($listing['name'] ?? '');
                $price = (float) ($listing['avg_price'] ?? 0);
                if ($price <= 0 || $name === '' || ! $this->startsWith($name, $prefix)
                    || $this->excluded($name, [...self::EXCLUDE, ...$exclusions])
                    || $this->excluded($this->normalize($listing['category'] ?? ''), self::EXCLUDED_CATEGORIES)) {
                    continue;
                }
                if (isset(self::FRESH[$staple])) {
                    // Loose only: its average is already per kg or per piece.
                    $sold = $this->normalize((string) ($listing['unit'] ?? ''));
                    if (preg_match(self::PACK_SIZE, $name) || preg_match('#\d\s*/\s*\d#', $name)
                        || ! in_array($sold, ['kg', 'kom', 'ko'], true)) {
                        continue;
                    }
                    [$amount, $unit] = [1, $sold === 'kg' ? 'kg' : 'piece'];
                } else {
                    [$amount, $unit] = $this->size($product, $listing);
                }
                $items[] = ['price' => round($price / $amount, 2), 'unit' => $unit,
                    'product' => $listing['name'], 'chain' => $listing['chain'] ?? null,
                    'ean' => $product['ean'] ?? null,
                    'date' => $listing['price_date'] ?? now()->toDateString()];
            }
        }

        return $items;
    }

    /**
     * [amount, 'kg'|'l'|'piece'] the listing's price is for. A count in the
     * name wins ("jaja 10/1", "6 kom"), then cijene.dev's normalised size,
     * then the chain's own quantity, then a size in the name ("800g"). Loose
     * produce sold by weight has no size and is already priced per kg.
     */
    private function size(array $product, array $listing): array
    {
        $name = $this->normalize($listing['name'] ?? '');
        if (preg_match('/(\d+)\s*\/\s*1\b|(\d+)\s*kom\b/u', $name, $count) && ($n = (int) ($count[1] ?: $count[2])) > 1) {
            return [$n, 'piece'];
        }
        $sizes = [
            [$product['quantity'] ?? null, $product['unit'] ?? null],
            [$listing['quantity'] ?? null, $listing['unit'] ?? null],
        ];
        foreach ($sizes as [$quantity, $unit]) {
            if ($parsed = $this->measure((string) $quantity, (string) $unit)) {
                return $parsed;
            }
        }
        if ($parsed = $this->packSize($name)) {
            return $parsed;
        }
        $unit = $this->normalize((string) ($listing['unit'] ?? ''));

        return in_array($unit, ['kg', 'l'], true) ? [1, $unit] : [1, 'piece'];
    }
}
