<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Croatian grocery prices from cijene.dev's public daily archives.
 *
 * No key or configuration: /v0/list names one ZIP per day with every chain's
 * products and per-store prices (the chains must publish them by law). One
 * archive is roughly 70 MB, so it is processed once, off the request path,
 * into a small basket of staple prices that meal plans read.
 */
class CroatianPrices
{
    public const LIST_URL = 'https://api.cijene.dev/v0/list';

    /** The processed basket: ['date' => Y-m-d, 'prices' => [english => price info]]. */
    private const BASKET_KEY = 'cijene:basket';

    /**
     * The basket meal plans are priced from: [english => [prefix, exclusions]].
     * Chains name products type-first ("MLIJEKO UHT 2,8% 1L", "KRUMPIR
     * MLADI"), so a product counts only when its name starts with the prefix
     * as a whole word (diacritics folded); a trailing * makes it a stem, for
     * "jabuka"/"jabuke". The English name is what plans and lists show.
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
        'onions' => ['luk', ['cesnjak', 'prah', 'pasta', 'suseni', 'prziv']],
        'garlic' => ['cesnjak', ['kaps', 'prah', 'granul']],
        'tomatoes' => ['rajcica', ['pasir', 'koncentr', 'susen', 'umak', 'sok', 'kecap', 'pelat', 'konzerv']],
        'peppers' => ['paprika', ['mljeven', 'slatka mlj', 'ljuta mlj', 'pasta', 'ajvar', 'cips', 'prah']],
        'cucumber' => ['krastav*', ['kiseli', 'salata']],
        'carrots' => ['mrkva', ['sok', 'salata']],
        'cabbage' => ['kupus', ['kiseli', 'salata', 'list']],
        'lettuce' => ['salata', ['od ', 'tun', 'krumpir', 'mix', 'kupus', 'rikul', 'grah']],
        'spinach' => ['spinat', ['krem', 'smrz']],
        'apples' => ['jabuk*', ['sok', 'cips', 'susen', 'kasa', 'ocat', 'pita']],
        'bananas' => ['banan*', ['cips', 'susen']],
        'lemons' => ['limun', ['sok', 'trava', 'kora', 'ulje']],
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
        'sugar' => ['secer', ['ugostit', 'vanil', 'u prahu', 'bez', 'kocke']],
        'coffee' => ['kava', ['kaps', 'jastuc', 'instant', '3u1', '2u1', 'napit', 'hlad']],
    ];

    /** Categories that are never food ("Mlijeko za tijelo" is body lotion). */
    private const EXCLUDED_CATEGORIES = ['kozmet', 'kucanst', 'drogerij', 'higijen', 'ljubim', 'njega'];

    /** Only real shelf products: never priced as food, whatever the name. */
    private const EXCLUDE = ['sprej', 'maramic', 'naljepnic', 'sampon', 'sapun', 'deterd',
        'hrana za', 'za pse', 'za macke', 'kozmet', 'aroma', 'svijec', 'miris'];

    /** The latest processed prices, or [] before the first archive is in. */
    public function staples(): array
    {
        return Cache::get(self::BASKET_KEY)['prices'] ?? [];
    }

    /** The archive date the prices come from, or null. */
    public function priceDate(): ?string
    {
        return Cache::get(self::BASKET_KEY)['date'] ?? null;
    }

    /**
     * True when cijene.dev lists an archive newer than the processed one.
     * The listing is asked at most once an hour.
     */
    public function stale(): bool
    {
        $latest = $this->latestArchive();

        return $latest !== null && $latest['date'] !== $this->priceDate();
    }

    /** ['date' => ..., 'url' => ...] of the newest archive, or null. */
    public function latestArchive(): ?array
    {
        return Cache::remember('cijene:latest-archive', now()->addHour(), function () {
            try {
                $archives = Http::timeout(15)->get(self::LIST_URL)->throw()->json('archives', []);
                usort($archives, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

                return isset($archives[0]['url'], $archives[0]['date'])
                    ? ['date' => $archives[0]['date'], 'url' => $archives[0]['url']] : null;
            } catch (Throwable $error) {
                report($error);

                return null;
            }
        });
    }

    /**
     * Downloads and processes the newest archive if it is not in yet. One run
     * at a time; a failure keeps the previous basket. Returns true when a new
     * basket was stored.
     */
    public function refresh(): bool
    {
        $lock = Cache::lock('cijene:refresh', 1800);
        if (! $lock->get()) {
            return false;
        }
        $path = storage_path('app/cijene-'.Str::random(8).'.zip');
        try {
            Cache::forget('cijene:latest-archive');
            $latest = $this->latestArchive();
            if ($latest === null || $latest['date'] === $this->priceDate()) {
                return false;
            }
            @set_time_limit(0);
            $response = Http::timeout(600)->withOptions(['sink' => $path])->get($latest['url'])->throw();
            // A faked or unsinkable response still carries its body.
            if (! is_file($path) || filesize($path) === 0) {
                file_put_contents($path, $response->body());
            }
            $prices = $this->process($path);
            if ($prices === []) {
                Log::warning('cijene.dev archive matched no staples', ['date' => $latest['date']]);

                return false;
            }
            Cache::forever(self::BASKET_KEY, ['date' => $latest['date'], 'prices' => $prices]);

            return true;
        } catch (Throwable $error) {
            report($error);

            return false;
        } finally {
            @unlink($path);
            $lock->release();
        }
    }

    /**
     * A typical cheap price for each staple, from an archive on disk. Each
     * product's shelf unit price (per kg, l or piece, which chains must
     * publish) is averaged over the chain's stores, so pack sizes compare.
     */
    public function process(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('cijene.dev archive could not be opened');
        }
        $terms = array_map(fn ($staple) => [$this->normalize($staple[0]),
            array_map(fn ($word) => $this->normalize($word), $staple[1])], self::STAPLES);
        $candidates = [];
        try {
            $chains = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('#^([^/]+)/products\.csv$#', $zip->getNameIndex($i), $match)) {
                    $chains[] = $match[1];
                }
            }
            foreach ($chains as $chain) {
                $matched = $this->matchProducts($zip, $chain, $terms);
                if ($matched === []) {
                    continue;
                }
                foreach ($this->averagePrices($zip, $chain, array_keys($matched)) as $productId => $price) {
                    foreach ($matched[$productId]['staples'] as $staple) {
                        $candidates[$staple][] = ['price' => $price, 'unit' => $matched[$productId]['unit'],
                            'product' => $matched[$productId]['name'], 'chain' => $chain];
                    }
                }
            }
        } finally {
            $zip->close();
        }
        $best = [];
        foreach ($candidates as $staple => $items) {
            if ($pick = $this->typical($items)) {
                $best[$staple] = $pick;
            }
        }
        ksort($best);

        return $best;
    }

    /**
     * A cheap but real price for a staple: products are compared in the unit
     * most of them are sold by (kg, l or piece), and the 25th percentile is
     * taken rather than the minimum, which is usually a clearance line or a
     * data error.
     */
    private function typical(array $items): ?array
    {
        $units = array_count_values(array_column($items, 'unit'));
        arsort($units);
        $unit = array_key_first($units);
        $items = array_values(array_filter($items, fn ($item) => $item['unit'] === $unit));
        if (count($items) === 0) {
            return null;
        }
        usort($items, fn ($a, $b) => $a['price'] <=> $b['price']);
        $pick = $items[(int) floor((count($items) - 1) * 0.25)];

        return ['price' => $pick['price'], 'unit' => $unit, 'product' => $pick['product'],
            'chain' => $pick['chain'], 'products' => count($items)];
    }

    /** [product_id => ['name' => ..., 'unit' => ..., 'staples' => [english...]]] for one chain. */
    private function matchProducts(ZipArchive $zip, string $chain, array $terms): array
    {
        $matched = [];
        foreach ($this->rows($zip, "{$chain}/products.csv") as $row) {
            $name = $this->normalize($row['name'] ?? '');
            if ($name === '' || $this->excluded($name, self::EXCLUDE)
                || $this->excluded($this->normalize($row['category'] ?? ''), self::EXCLUDED_CATEGORIES)) {
                continue;
            }
            foreach ($terms as $english => [$prefix, $exclusions]) {
                if ($this->startsWith($name, $prefix) && ! $this->excluded($name, $exclusions)) {
                    $matched[$row['product_id']] ??= ['name' => $row['name'], 'unit' => $this->baseUnit($row), 'staples' => []];
                    $matched[$row['product_id']]['staples'][] = $english;
                }
            }
        }

        return $matched;
    }

    /** Average price per matched product across a chain's stores. */
    private function averagePrices(ZipArchive $zip, string $chain, array $productIds): array
    {
        $wanted = array_flip(array_map('strval', $productIds));
        $sums = [];
        foreach ($this->rows($zip, "{$chain}/prices.csv") as $row) {
            $id = (string) ($row['product_id'] ?? '');
            if (! isset($wanted[$id])) {
                continue;
            }
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            if ($unitPrice <= 0) {
                continue;
            }
            $sums[$id] ??= ['total' => 0.0, 'count' => 0];
            $sums[$id]['total'] += $unitPrice;
            $sums[$id]['count']++;
        }
        $averages = [];
        foreach ($sums as $id => $sum) {
            $averages[$id] = round($sum['total'] / $sum['count'], 2);
        }

        return $averages;
    }

    /** Streams a CSV from the archive as associative rows. */
    private function rows(ZipArchive $zip, string $name): \Generator
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return;
        }
        try {
            $header = fgetcsv($stream, escape: '\\');
            if (! $header) {
                return;
            }
            $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)), $header);
            while (($values = fgetcsv($stream, escape: '\\')) !== false) {
                if (count($values) === count($header)) {
                    yield array_combine($header, $values);
                }
            }
        } finally {
            fclose($stream);
        }
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
            if (str_contains($name, $word)) {
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

    /**
     * What the shelf unit price is per: kg, l or piece. Chains fill the unit
     * and quantity columns differently, so the unit column is read first, then
     * the quantity, then a pack size in the name ("500 g", "1L"); eggs and
     * other counted goods fall through to piece.
     */
    private function baseUnit(array $row): string
    {
        // '1kg' and '500g' in the unit column count as the bare unit.
        $unit = preg_replace('/^[0-9.,\s]+/', '', strtolower(trim($row['unit'] ?? '')));
        $sources = [$unit, strtolower($row['quantity'] ?? ''), $this->normalize($row['name'] ?? '')];
        foreach ($sources as $index => $text) {
            if ($index === 0) {
                if (in_array($text, ['kg', 'g', 'dkg'], true)) {
                    return 'kg';
                }
                if (in_array($text, ['l', 'ml', 'dl', 'cl'], true)) {
                    return 'l';
                }
                if (in_array($text, ['kom', 'ko', 'kos'], true) && str_contains($sources[1].' '.$sources[2], 'kom')) {
                    return 'piece';
                }

                continue;
            }
            if (preg_match('/\d\s*(kg|dkg|g|gr)\b/u', $text)) {
                return 'kg';
            }
            if (preg_match('/\d\s*(l|ml|dl|cl)\b/u', $text)) {
                return 'l';
            }
        }

        return 'piece';
    }
}
