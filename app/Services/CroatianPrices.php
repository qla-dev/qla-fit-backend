<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
class CroatianPrices
{
    public const SEARCH_URL = 'https://api.cijene.dev/v1/products/';

    /** The basket: ['date' => Y-m-d, 'fetched' => Y-m-d, 'prices' => [english => price info]]. */
    private const BASKET_KEY = 'cijene:basket';

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

    /** Today's prices, fetched on the first call of the day; [] without a key. */
    public function staples(): array
    {
        $this->ensureFresh();

        return Cache::get(self::BASKET_KEY)['prices'] ?? [];
    }

    /** The shelf-price date the basket comes from, or null. */
    public function priceDate(): ?string
    {
        return Cache::get(self::BASKET_KEY)['date'] ?? null;
    }

    /** Fetches the basket unless it was already fetched today. */
    public function ensureFresh(): void
    {
        if (config('fitness.cijene.key') && (Cache::get(self::BASKET_KEY)['fetched'] ?? null) !== now()->toDateString()) {
            $this->refresh();
        }
    }

    /**
     * Searches every staple in parallel and stores the basket. One run at a
     * time; a failure keeps the previous basket. Returns true when stored.
     */
    public function refresh(): bool
    {
        $key = config('fitness.cijene.key');
        if (! $key) {
            return false;
        }
        $lock = Cache::lock('cijene:refresh', 60);
        if (! $lock->get()) {
            return false;
        }
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($staple) => $pool->as($staple)->withToken($key)->timeout(10)
                    ->get(self::SEARCH_URL, ['q' => $this->query($staple), 'limit' => 100]),
                array_keys(self::STAPLES)
            ));
            $prices = [];
            $dates = [];
            foreach ($responses as $staple => $response) {
                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }
                if ($pick = $this->typical($this->candidates($staple, $response->json('products', [])))) {
                    $prices[$staple] = $pick;
                    $dates[] = $pick['date'];
                    unset($prices[$staple]['date']);
                }
            }
            if ($prices === []) {
                Log::warning('cijene.dev returned no staple prices');

                return false;
            }
            ksort($prices);
            Cache::forever(self::BASKET_KEY, ['date' => max($dates), 'fetched' => now()->toDateString(), 'prices' => $prices]);

            return true;
        } catch (Throwable $error) {
            report($error);

            return false;
        } finally {
            $lock->release();
        }
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
                    'date' => $listing['price_date'] ?? now()->toDateString()];
            }
        }

        return $items;
    }

    /**
     * A cheap but real price for a staple: listings are compared in the unit
     * most of them are sold by, and the 25th percentile is taken rather than
     * the minimum, which is usually a clearance line or a data error.
     */
    private function typical(array $items): ?array
    {
        if ($items === []) {
            return null;
        }
        $units = array_count_values(array_column($items, 'unit'));
        arsort($units);
        $unit = array_key_first($units);
        $items = array_values(array_filter($items, fn ($item) => $item['unit'] === $unit));
        usort($items, fn ($a, $b) => $a['price'] <=> $b['price']);
        $pick = $items[(int) floor((count($items) - 1) * 0.25)];

        return ['price' => $pick['price'], 'unit' => $unit, 'product' => $pick['product'],
            'chain' => $pick['chain'], 'products' => count($items), 'date' => $pick['date']];
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
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kg|dkg|gr|g|l|dl|cl|ml)\b/u', $name, $match)
            && ($parsed = $this->measure($match[1], $match[2]))) {
            return $parsed;
        }
        $unit = $this->normalize((string) ($listing['unit'] ?? ''));

        return in_array($unit, ['kg', 'l'], true) ? [1, $unit] : [1, 'piece'];
    }

    /** "0,100" + "kg", or "0,8 KG" alone, as [amount in kg or l, unit]. */
    private function measure(string $quantity, string $unit): ?array
    {
        $text = $this->normalize($quantity.' '.$unit);
        if (! preg_match('/(\d*[.,]?\d+)\s*(kg|dkg|gr|g|l|dl|cl|ml)\b/u', $text, $match)) {
            return null;
        }
        $amount = (float) str_replace(',', '.', $match[1]);
        $scale = ['kg' => [1, 'kg'], 'dkg' => [0.01, 'kg'], 'gr' => [0.001, 'kg'], 'g' => [0.001, 'kg'],
            'l' => [1, 'l'], 'dl' => [0.1, 'l'], 'cl' => [0.01, 'l'], 'ml' => [0.001, 'l']][$match[2]];
        $amount *= $scale[0];

        return $amount > 0 ? [$amount, $scale[1]] : null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'd']);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function excluded(string $name, array $words): bool
    {
        foreach ($words as $word) {
            if ($word !== '' && str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }

    /** "pileca prsa" at the start, then a non-letter; "jabuk*" is a stem. */
    private function startsWith(string $name, string $prefix): bool
    {
        if (str_ends_with($prefix, '*')) {
            return str_starts_with($name, substr($prefix, 0, -1));
        }

        return preg_match('/^'.preg_quote($prefix, '/').'(?![\p{L}])/u', $name) === 1;
    }
}
