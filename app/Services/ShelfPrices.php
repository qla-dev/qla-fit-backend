<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real grocery shelf prices for one region, kept as a small daily basket of
 * staples that meal plans are priced from.
 *
 * Each region reads its own source and searches it in its own language, but
 * every basket has the same staples (the English keys of STAPLES) and the
 * same shape: [english => ['price' => per unit, 'unit' => kg|l|piece,
 * 'product', 'chain', 'ean' (or null), 'chains' => [chain => price],
 * 'products' => count]]. Listings are compared per kg, l or piece and the
 * 25th percentile is kept, so a clearance line never prices a week.
 *
 * A source is read at most once a day, by `prices:refresh` or the first plan
 * of the day. When it is down the last good basket is kept, and with none the
 * plan is estimated.
 */
abstract class ShelfPrices
{
    /** Regions with real shelf prices, and what prices each. */
    public const REGIONS = ['HR' => CroatianPrices::class, 'RS' => SerbianPrices::class, 'NL' => DutchPrices::class];

    /** What a plan priced from this basket says in `prices`; also the cache prefix. */
    public const SOURCE = '';

    public const CURRENCY = 'EUR';

    /** How the model is told whose prices these are: "Croatian shelf prices". */
    public const COUNTRY = '';

    /** [english => [local search terms, exclusions, …]]; every region has the same keys. */
    public const STAPLES = [];

    /** How long one refresh may hold the lock. */
    protected const LOCK_SECONDS = 60;

    /** After a failed or skipped refresh, plans wait this long before trying again. */
    private const RETRY_MINUTES = 30;

    /** Display names of chains whose code does not read well on its own. */
    protected const NAMES = [];

    /** The prices for a region, or null where plans are estimated. */
    public static function for(string $region): ?self
    {
        $class = self::REGIONS[$region] ?? null;

        return $class ? app($class) : null;
    }

    /** Whether the source can be read at all (a key is set, it is switched on). */
    abstract public function enabled(): bool;

    /**
     * Today's listings that match a staple, as [english => [item, …]], where
     * an item is ['price' => per unit, 'unit' => 'kg'|'l'|'piece', 'product',
     * 'chain', 'ean', 'date' => Y-m-d]. May throw; the basket is then kept.
     */
    abstract protected function fetch(): array;

    /** Today's prices, fetched on the first call of the day; [] when there are none. */
    public function staples(): array
    {
        $this->ensureFresh();

        return Cache::get($this->key('basket'))['prices'] ?? [];
    }

    /** The shelf-price date the basket comes from, or null. */
    public function priceDate(): ?string
    {
        return Cache::get($this->key('basket'))['date'] ?? null;
    }

    /**
     * Fetches the basket unless it was already fetched today. A source that
     * is down is not asked again for a while, so plans do not each wait on it.
     */
    public function ensureFresh(): void
    {
        if ($this->enabled() && (Cache::get($this->key('basket'))['fetched'] ?? null) !== now()->toDateString()
            && Cache::add($this->key('retry'), true, now()->addMinutes(self::RETRY_MINUTES))) {
            $this->refresh();
        }
    }

    /**
     * Reads the source and stores the basket. One run at a time; a failure
     * keeps the previous basket. Returns true when stored.
     */
    public function refresh(): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $lock = Cache::lock($this->key('refresh'), static::LOCK_SECONDS);
        if (! $lock->get()) {
            return false;
        }
        try {
            $prices = [];
            $dates = [];
            foreach ($this->fetch() as $staple => $items) {
                if (isset(static::STAPLES[$staple]) && ($pick = $this->typical($items))) {
                    $dates[] = $pick['date'];
                    unset($pick['date']);
                    $prices[$staple] = $pick;
                }
            }
            if ($prices === []) {
                Log::warning(static::SOURCE.' returned no staple prices');

                return false;
            }
            ksort($prices);
            Cache::forever($this->key('basket'), ['date' => max($dates), 'fetched' => now()->toDateString(), 'prices' => $prices]);

            return true;
        } catch (Throwable $error) {
            report($error);

            return false;
        } finally {
            $lock->release();
        }
    }

    /** Every chain the basket has a price from, as [code, name]. */
    public function vendors(): array
    {
        $codes = collect($this->staples())->flatMap(fn ($staple) => array_keys($staple['chains'] ?? []))->unique()->sort()->values();

        return $codes->map(fn ($code) => ['code' => $code, 'name' => $this->vendorName($code)])->all();
    }

    /**
     * What a cart costs at each chain. Items carry the price the plan gave
     * them and, when it came from the basket, the staple it was priced from;
     * a chain's price for that staple scales the item by how far it sits from
     * the typical price. Items a chain has no price for keep their own, and
     * `matched` says how many it priced. Cheapest well-covered chains first.
     *
     * @param  array<int, array{staple?: string|null, price?: float|int|null}>  $items
     * @return array<int, array{code: string, name: string, total: float, matched: int, prices: array<int, float|null>}>
     */
    public function compare(array $items): array
    {
        $staples = $this->staples();
        $comparable = count(array_filter($items, fn ($item) => isset($staples[$item['staple'] ?? ''])));
        $vendors = [];
        foreach ($this->vendors() as $vendor) {
            $total = 0.0;
            $matched = 0;
            $prices = [];
            foreach ($items as $item) {
                $price = isset($item['price']) ? (float) $item['price'] : null;
                $staple = $staples[$item['staple'] ?? ''] ?? null;
                $own = $staple['chains'][$vendor['code']] ?? null;
                if ($price !== null && $own !== null && $staple['price'] > 0) {
                    $price = round($price * $own / $staple['price'], 2);
                    $matched++;
                }
                $prices[] = $price;
                $total += $price ?? 0;
            }
            // A chain with none of the cart (a drugstore) says nothing about it.
            if ($matched > 0 || $comparable === 0) {
                $vendors[] = [...$vendor, 'total' => round($total, 2), 'matched' => $matched, 'prices' => $prices];
            }
        }
        // A chain that stocks half the cart is not "cheaper" for leaving the
        // rest at the typical price, so coverage ranks before the total.
        $covered = fn ($vendor) => $comparable === 0 || $comparable <= $vendor['matched'] * 2;
        usort($vendors, fn ($a, $b) => [$covered($b), $a['total'], $b['matched']] <=> [$covered($a), $b['total'], $a['matched']]);

        return $vendors;
    }

    protected function key(string $name): string
    {
        return static::SOURCE.':'.$name;
    }

    /** "trgovina-krk" as "Trgovina Krk"; a short code is an acronym, "dm" as "DM". */
    protected function vendorName(string $code): string
    {
        if (isset(static::NAMES[$code])) {
            return static::NAMES[$code];
        }
        $words = preg_split('/[-_\s]+/', $code, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) === 1 && strlen($code) <= 3) {
            return strtoupper($code);
        }

        return implode(' ', array_map('ucfirst', $words));
    }

    /**
     * A cheap but real price for a staple: listings are compared in the unit
     * most of them are sold by, and the 25th percentile is taken rather than
     * the minimum, which is usually a clearance line or a data error. Each
     * chain gets the same pick from its own listings, for the comparison.
     */
    protected function typical(array $items): ?array
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
        $chains = [];
        foreach (collect($items)->filter(fn ($item) => $item['chain'])->groupBy('chain') as $chain => $listings) {
            $chains[$chain] = $listings[(int) floor((count($listings) - 1) * 0.25)]['price'];
        }
        ksort($chains);

        return ['price' => $pick['price'], 'unit' => $unit, 'product' => $pick['product'],
            'chain' => $pick['chain'], 'ean' => $pick['ean'], 'chains' => $chains,
            'products' => count($items), 'date' => $pick['date']];
    }

    /** A count of pieces in a name: "jaja 10/1", "6 kom", "10 stuks", "15stk". */
    protected function count(string $name): ?int
    {
        if (preg_match('/(\d+)\s*\/\s*1(?!\d)|(\d+)\s*(?:kom|stuks|stuk|stk|st)(?![\p{L}])/u', $name, $match)) {
            $n = (int) ($match[1] !== '' ? $match[1] : $match[2]);

            return $n > 1 ? $n : null;
        }

        return null;
    }

    /**
     * A pack weight or volume in a text, as [amount in kg or l, unit]:
     * "800g", "0,8 KG", "1.5 liter", "6 x 1 L" (six litres).
     */
    protected function packSize(string $text): ?array
    {
        $units = 'kilogram|kilo|kg|dkg|gram|gr|g|milliliter|ml|centiliter|cl|dl|liter|ltr|lit|l';
        if (! preg_match('/(?:(\d+)\s*x\s*)?(\d*[.,]?\d+)\s*('.$units.')(?![\p{L}])/u', $this->normalize($text), $match)) {
            return null;
        }
        $amount = (float) str_replace(',', '.', $match[2]) * (($match[1] ?? '') !== '' ? (int) $match[1] : 1);
        $scale = match ($match[3]) {
            'kilogram', 'kilo', 'kg' => [1, 'kg'], 'dkg' => [0.01, 'kg'], 'gram', 'gr', 'g' => [0.001, 'kg'],
            'liter', 'ltr', 'lit', 'l' => [1, 'l'], 'dl' => [0.1, 'l'], 'centiliter', 'cl' => [0.01, 'l'],
            default => [0.001, 'l'],
        };
        $amount *= $scale[0];

        return $amount > 0 ? [$amount, $scale[1]] : null;
    }

    /** "0,100" + "kg", or "0,8 KG" alone, as [amount in kg or l, unit]. */
    protected function measure(string $quantity, string $unit): ?array
    {
        return $this->packSize($quantity.' '.$unit);
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'd']);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    protected function excluded(string $name, array $words): bool
    {
        foreach ($words as $word) {
            if ($word !== '' && str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }

    /** "pileca prsa" at the start, then a non-letter; "jabuk*" is a stem. */
    protected function startsWith(string $name, string $prefix): bool
    {
        if (str_ends_with($prefix, '*')) {
            return str_starts_with($name, substr($prefix, 0, -1));
        }

        return preg_match('/^'.preg_quote($prefix, '/').'(?![\p{L}])/u', $name) === 1;
    }
}
