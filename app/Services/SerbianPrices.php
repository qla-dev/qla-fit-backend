<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Serbian grocery prices from the retailers' own price lists on data.gov.rs.
 *
 * Since 1 September 2026 retailers must publish their whole price list as a
 * daily CSV (Pravilnik o uslovima, sadržaju i načinu objavljivanja
 * cenovnika, "Sl. glasnik RS" 76/2026), each under its own data.gov.rs
 * dataset, in the same 15 columns: category, product name, brand, barcode,
 * unit, retailer format, price-list date, regular price, unit price, sale
 * price and dates, VAT, and the kind of list (the current one, VAZECI_
 * CENOVNIK, or a monthly snapshot, MESECNI_PRESEK). Product names are
 * Serbian Latin, type first ("PILEĆE GRUDI …", "Jaja … 10/1").
 *
 * Once a day the grocery chains below are downloaded in parallel through
 * data.gov.rs's stable resource links (tens of MB, streamed to temporary
 * files and read line by line). Licence: Open Data Licence of Serbia
 * (attribution: data.gov.rs and the retailer, with the download date).
 */
class SerbianPrices extends ShelfPrices
{
    public const SOURCE = 'datagovrs';

    public const CURRENCY = 'RSD';

    public const COUNTRY = 'Serbian';

    /** A data.gov.rs resource's stable link, redirecting to its current file. */
    public const RESOURCE_URL = 'https://data.gov.rs/sr/datasets/r/';

    /**
     * The grocery chains read, as [code => data.gov.rs resource id]. METRO
     * is left out: it lists case prices against single-unit sizes.
     */
    public const RETAILERS = [
        'lidl' => '79be27de-a311-489d-86ae-6df3c7a65345',
        'maxi' => 'aa0a4ab4-1dc8-49e2-abef-67612a2abcbb',
        'idea' => '1ce4a0a7-1e8e-4691-afc5-a1919a38398f',
        'univerexport' => 'a6dadf8c-912f-49ae-a03f-989c8dfd71fa',
        'gomex' => '20384e27-4671-414d-8bfa-8460559ffd53',
    ];

    protected const NAMES = ['lidl' => 'Lidl', 'maxi' => 'Maxi', 'idea' => 'IDEA', 'univerexport' => 'Univerexport', 'gomex' => 'Gomex'];

    /** Downloading the big lists takes a while. */
    protected const LOCK_SECONDS = 300;

    /**
     * [english => [name prefixes, exclusions, category]]: a listing counts
     * when its name starts with a prefix as a whole word (diacritics folded,
     * Cyrillic read as Latin; a trailing * is a stem) and its category, when
     * given, contains the word, so "paradajz" is fresh tomatoes, not a sauce.
     */
    public const STAPLES = [
        'chicken breast' => [['pilece grudi', 'pileci file', 'pileca prsa'], ['narez', 'dimlj', 'pecen', 'pohov', 'crev', 'kobas', 'virsl', 'pastet', 'salam', 'delikat', 'gril', 'marin', 'bujon', 'supa'], 'meso'],
        'minced beef' => [['mleveno junece', 'junece mleveno', 'mleveno meso junece', 'juneca mlevena', 'junetina mlevena'], ['svinj', 'cevap', 'pljesk'], 'meso'],
        'pork loin' => [['svinjski kare', 'svinjska karmenadla', 'svinjski karmenadl*', 'svinjska kotlet'], ['dimlj', 'pecen', 'susen', 'narez'], 'meso'],
        'eggs' => [['jaja'], ['prepel', 'cokolad', 'boj', 'naljep', 'kuvan', 'u prahu', 'ukras'], null],
        'milk' => [['mleko'], ['cokolad', 'kokos', 'zob', 'ovs', 'badem', 'soj', 'pirin', 'u prahu', 'kondenz', 'telo', 'body', 'kiselo', 'zam', 'formul', 'za kafu', 'lice', 'cist', 'kozje', 'dezert', 'kakao', 'napit', 'jagod', 'vanil', 'banan', 'humana', 'bebe'], 'mlek'],
        'yogurt' => [['jogurt'], ['voc', 'grck', 'cokol', 'ovs', 'smoothie', 'napit', 'dezert', 'jagod', 'visnj', 'banan', 'vanil', 'kiv', 'sumsk', 'musli', 'tecni obrok', 'protein'], 'mlek'],
        'cottage cheese' => [['sir svezi', 'svezi sir', 'mladi sir', 'sir mladi'], ['namaz', 'krem', 'pavlak'], 'mlek'],
        'cheese' => [['sir gauda', 'gauda', 'sir edamer', 'edamer', 'sir trapist', 'trapist'], ['listic', 'narend', 'rendan', 'slajs'], 'mlek'],
        'butter' => [['maslac', 'puter od mleka'], ['kikiriki', 'kakao', 'bilj', 'lesnik', 'orah', 'badem', 'namaz', ' od ', 'suncok'], null],
        'olive oil' => [['maslinovo ulje', 'ulje maslinovo'], ['sprej'], 'ulj'],
        'rice' => [['pirinac'], ['povrc', 'napit', 'mlek', 'krek', 'kapsul', 'gotov', 'vafl', 'pahuljic', ' sa '], 'pirin'],
        'pasta' => [['testenina', 'spagete', 'spageti', 'spaghetti', 'makarone', 'penne', 'pene', 'fusili', 'fusilli'], ['gotov', 'salat', ' sa ', 'sir', 'sos', 'umak'], 'testen'],
        'oats' => [['ovsene pahuljice', 'pahuljice ovsene', 'ovsene'], ['cokolad', 'med', 'kasa sa', 'voc'], null],
        'bread' => [['hleb'], ['mrvic', 'prezl', 'dvopek', 'tost', 'hrskav', 'zamrz'], 'hleb'],
        'flour' => [['brasno'], ['kukuruz', 'pirin', 'bademov', 'kokos', 'smesa', 'palacink', 'krmn', 'heljd', 'proja'], 'brasn'],
        'potatoes' => [['krompir'], ['cips', 'pire', 'pomfrit', 'smrz', 'kroket', 'slajs', 'prziv', 'salat', 'slatki', 'batat', 'cicok'], 'svez'],
        'onions' => [['luk crni', 'crni luk', 'luk crveni', 'crveni luk', 'luk'], ['beli', 'prah', 'prziv', 'susen', 'kockic', 'smrz', 'granul', 'pasta', 'praziluk', 'mladi'], 'svez'],
        'garlic' => [['beli luk', 'luk beli'], ['prah', 'granul', 'kaps', 'mlev', 'susen', 'pasta'], 'svez'],
        'tomatoes' => [['paradajz'], ['pire', 'pasir', 'koncentr', 'susen', 'sos', 'sok', 'kecap', 'pelat', 'konzerv', 'pulpa', 'ajvar', 'pasta', 'umak'], 'svez'],
        'peppers' => [['paprika'], ['mlev', 'pasta', 'ajvar', 'cips', 'prah', 'pecen', 'kisel', 'punjen', 'sos', 'u ulju', 'ljuta'], 'svez'],
        'cucumber' => [['krastav*'], ['kisel', 'salat', 'kornison', 'steril', 'ocat', 'tegl', 'fermen'], 'svez'],
        'carrots' => [['sargarepa', 'mrkva'], ['sok', 'salat', 'smrz', 'kock', 'rend'], 'svez'],
        'cabbage' => [['kupus'], ['kisel', 'salat', 'list', 'sarm', 'rend', 'kelj', 'karfiol', 'brokol', 'secen', 'opran'], 'svez'],
        'lettuce' => [['zelena salata', 'salata zelena', 'ajsberg', 'salata ajsberg', 'salata kristal', 'salata puterica', 'salata'], ['tun', 'krompir', 'mix', 'kupus', 'rukol', ' sa ', 'pilec', 'grck', 'ruska', 'vitamin', 'cvekl', 'paradajz', 'sopsk'], 'svez'],
        'spinach' => [['spanac'], ['krem', 'smrz', 'pita', 'pire', 'burek'], 'svez'],
        'apples' => [['jabuk*'], ['sok', 'cips', 'susen', 'kasa', 'sirc', 'pita', 'strudl', 'pire', 'nektar', 'dzem', 'cider', 'caj', 'kompot'], 'svez'],
        'bananas' => [['banan*'], ['cips', 'susen', 'bananic', 'pire', 'sok', 'smoothie'], 'svez'],
        'lemons' => [['limun'], ['sok', 'trava', 'kora', 'ulje', 'secer', 'caj', 'aroma', 'limunad', 'sirup', 'koncentr', 'kiselin'], 'svez'],
        'beans' => [['pasulj'], ['mahun', 'salat', 'kobas', ' sa ', 'corba', 'gotov', 'prebranac', 'posni', 'varivo', 'zeleni', 'zuti'], 'mahun'],
        'lentils' => [['socivo'], ['corba', 'supa', 'salat', 'gotov', ' sa '], null],
        'chickpeas' => [['leblebij*', 'slanutak', 'naut'], ['humus', 'namaz', 'cips', 'brasno', 'przen', 'pecen'], null],
        'tuna' => [['tunjevina', 'tuna'], ['pesto', 'salat', 'namaz', 'pastet', 'povrc', 'sendvic', 'sos', 'pasta'], 'rib'],
        'sardines' => [['sardin*', 'srdel*'], ['pastet', 'namaz'], 'rib'],
        'hake' => [['oslic', 'file oslic*'], ['pohov', 'stapic', 'glava', 'burger', 'panir', 'ulj', 'sos', 'paradajz', 'pastet'], null],
        'canned tomatoes' => [['pelat*', 'paradajz pelat*', 'paradajz pulpa', 'paradajz secen*', 'paradajz u komad*'], ['pizza', 'sos', 'zacin'], null],
        'frozen vegetables' => [['mesavina povrca', 'povrce mesavina', 'smrznuto povrce', 'povrce smrznuto', 'povrce za'], ['supa', 'corba', 'pita', 'gotov'], 'smrz'],
        'walnuts' => [['orah', 'orasi', 'jezgro oraha'], ['cokolad', 'med', 'kokos', 'lesnik', 'kikiriki', 'ulje', 'muskat', 'mlev', 'kolac', 'torta'], null],
        'honey' => [['med'], ['maramic', 'bombon', 'sapun', 'krem', 'medenjak', 'kolac', 'kandir'], null],
        'sugar' => [['secer'], ['vanil', 'u prahu', 'bez', 'kocke', 'mlev', 'smed', 'trsk', 'kokos', 'zamen', 'dekor', 'kockic', 'stevi', 'zele'], null],
        'coffee' => [['kafa'], ['kaps', 'jastuc', 'instant', '3u1', '2u1', '3 u 1', '2 u 1', '3in1', '2in1', 'nescafe', 'napit', 'hlad', 'cappuccino', 'kapucino', 'ledena', 'frape', 'cups', 'pods'], 'kaf'],
    ];

    /** Never food, whatever the name. */
    private const EXCLUDE = ['sprej', 'maramic', 'naljepnic', 'sampon', 'sapun', 'deterd', 'hrana za', 'za pse', 'za macke', 'kozmet', 'aroma', 'svec', 'miris'];

    /**
     * Categories that are never food, by a word they start (the Pravilnik's
     * own list: chemicals, paper goods, hygiene, babies, alcohol, pets).
     * "Bezalkoholna pića" is not alcohol.
     */
    private const EXCLUDED_CATEGORIES = '/(?<![\p{L}])(hemij|galanterij|higijen|kozmet|beb|pelen|alkohol|ljubim)/u';

    /** The columns read, by words their header contains (headers differ slightly between retailers). */
    private const COLUMNS = [
        'name' => 'naziv proizvoda', 'category' => 'naziv kategorije', 'barcode' => 'barkod', 'unit' => 'jedinica mere',
        'date' => 'datum cenovnika', 'price' => 'redovna cena', 'sale' => 'snizena cena', 'kind' => 'vrsta',
    ];

    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ђ' => 'd', 'е' => 'e', 'ж' => 'z', 'з' => 'z', 'и' => 'i',
        'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'ћ' => 'c', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'c', 'џ' => 'dz', 'ш' => 's',
    ];

    public function enabled(): bool
    {
        return (bool) config('fitness.prices.datagovrs', true);
    }

    /** Every retailer's list downloaded in parallel to a temporary file, then read line by line. */
    protected function fetch(): array
    {
        $files = array_map(fn () => tempnam(sys_get_temp_dir(), 'cenovnik'), self::RETAILERS);
        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($code) => $pool->as($code)->sink($files[$code])->connectTimeout(15)->timeout(180)
                    ->get(self::RESOURCE_URL.self::RETAILERS[$code]),
                array_keys(self::RETAILERS)
            ));
            $found = [];
            foreach ($responses as $code => $response) {
                if ($response instanceof Response && $response->successful()) {
                    foreach ($this->listings($files[$code], $code) as $staple => $items) {
                        $found[$staple] = [...($found[$staple] ?? []), ...$items];
                    }
                } else {
                    report(new \RuntimeException("data.gov.rs price list for {$code} could not be downloaded"));
                }
            }

            return $found;
        } finally {
            array_map(fn ($file) => @unlink($file), $files);
        }
    }

    /**
     * One retailer's current listings that match a staple, as [english =>
     * [item, …]]. A file holds the current list and the month-start
     * snapshots; only the current list counts, or else the latest date.
     */
    public function listings(string $path, string $chain): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) {
            return [];
        }
        try {
            $header = $this->utf8((string) fgets($handle));
            $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($header, $d))->first();
            $columns = $this->columns(str_getcsv($header, $delimiter, '"', ''));
            if (! isset($columns['name'], $columns['price'])) {
                return [];
            }
            $staples = $this->index();
            $rows = [];
            $hasCurrent = false;
            $latest = '';
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $date = $this->date($row[$columns['date'] ?? -1] ?? '');
                $current = str_contains(strtolower($row[$columns['kind'] ?? -1] ?? ''), 'vaze');
                $hasCurrent = $hasCurrent || $current;
                $latest = max($latest, $date);
                $name = $this->normalize($this->utf8($row[$columns['name']] ?? ''));
                $staple = $this->match($name, $staples, $this->normalize($this->utf8($row[$columns['category'] ?? -1] ?? '')));
                if ($staple === null) {
                    continue;
                }
                $price = $this->number($row[$columns['price']] ?? '') ?: $this->number($row[$columns['sale'] ?? -1] ?? '');
                if ($price <= 0) {
                    continue;
                }
                [$amount, $unit] = $this->size($name, $this->normalize($row[$columns['unit'] ?? -1] ?? ''));
                $barcode = trim($row[$columns['barcode'] ?? -1] ?? '');
                // The same product is listed once per store format and snapshot.
                $rows[$name.'|'.$price.'|'.$date.'|'.(int) $current] = [$staple, $current, $date, [
                    'price' => round($price / $amount, 2), 'unit' => $unit, 'product' => trim($this->utf8($row[$columns['name']])),
                    'chain' => $chain, 'ean' => preg_match('/^\d{8,14}$/', $barcode) ? $barcode : null, 'date' => $date ?: now()->toDateString(),
                ]];
            }
        } finally {
            fclose($handle);
        }
        $found = [];
        foreach ($rows as [$staple, $current, $date, $item]) {
            if ($hasCurrent ? $current : $date === $latest) {
                $found[$staple][] = $item;
            }
        }

        return $found;
    }

    /**
     * [amount, 'kg'|'l'|'piece'] a price is for. Sold by the piece, a count
     * in the name wins ("Jaja 10/1"); then a pack size in the name ("500G");
     * then the unit it is sold by, loose produce being priced per kg. A
     * "10/1" on something sold by the kg is a carton, not a count.
     */
    private function size(string $name, string $sold): array
    {
        $sold = match (true) {
            in_array($sold, ['kg', 'g', 'gr', 'gram', 'kilogram'], true) => 'kg',
            in_array($sold, ['l', 'lit', 'litar', 'ml', 'mililitar'], true) => 'l',
            default => 'piece',
        };
        if ($sold === 'piece' && ($n = $this->count($name))) {
            return [$n, 'piece'];
        }

        return $this->packSize($name) ?? [1, $sold];
    }

    /** The staple a listing is, or null. */
    private function match(string $name, array $index, string $category): ?string
    {
        if ($name === '' || preg_match(self::EXCLUDED_CATEGORIES, $category)) {
            return null;
        }
        foreach ($index[mb_substr($name, 0, 3)] ?? [] as [$staple, $prefix, $exclusions, $wanted]) {
            if ($this->startsWith($name, $prefix) && ! $this->excluded($name, $exclusions)
                && ($wanted === null || $category === '' || str_contains($category, $wanted))) {
                return $staple;
            }
        }

        return null;
    }

    /** Staple prefixes by their first three letters, so each row is checked against a few. */
    private function index(): array
    {
        $index = [];
        foreach (self::STAPLES as $staple => [$prefixes, $exclusions, $category]) {
            $exclusions = [...self::EXCLUDE, ...$exclusions];
            foreach ($prefixes as $prefix) {
                $index[mb_substr($prefix, 0, 3)][] = [$staple, $prefix, $exclusions, $category];
            }
        }

        return $index;
    }

    /** [column => index], matching headers loosely ("Naziv trgovca – formata", a BOM, any case). */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $i => $title) {
            $title = preg_replace('/[^a-z0-9]+/', ' ', $this->normalize(preg_replace('/^\x{FEFF}/u', '', $title)));
            foreach (self::COLUMNS as $column => $words) {
                if (! isset($columns[$column]) && str_contains($title, $words)) {
                    $columns[$column] = $i;
                }
            }
        }

        return $columns;
    }

    /** "1.234,56", "1234.56" or "269,99" as a number. */
    private function number(string $text): float
    {
        $text = preg_replace('/[^\d.,]/', '', $text);
        if (str_contains($text, ',') && str_contains($text, '.')) {
            $text = strrpos($text, ',') > strrpos($text, '.') ? str_replace(['.', ','], ['', '.'], $text) : str_replace(',', '', $text);
        }

        return (float) str_replace(',', '.', $text);
    }

    /** "01-11-2026", "01.11.2026." or "2026-11-01" as Y-m-d; '' when unreadable. */
    private function date(string $text): string
    {
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return preg_match('/(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})/', $text, $m)
            ? sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]) : '';
    }

    /** Lists are UTF-8 by law; an older Windows-1250 export is read as such. */
    private function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1250');
    }

    protected function normalize(string $text): string
    {
        return parent::normalize(strtr(mb_strtolower($text), self::CYRILLIC));
    }
}
