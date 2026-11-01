<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Grants what an account bought in the app, from RevenueCat's own record of
 * it (REST API v2, secret key). Each coin transaction is granted once; the
 * training level follows the `trainingN` entitlements' expiry. Same flow as
 * putni-nalozi's credit sync.
 */
class RevenueCatPurchases
{
    /** Programs the account's training level unlocks, while it runs. */
    public function programs(User $user): array
    {
        $level = $this->level($user);

        return collect(config('purchases.programs'))->filter(fn (int $needs) => $needs <= $level)
            ->keys()->sort()->values()->all();
    }

    /** The training level that is running now, 0 for none. */
    public function level(User $user): int
    {
        $runs = $user->training_level && ($user->training_until === null || $user->training_until->isFuture());

        return $runs ? (int) $user->training_level : 0;
    }

    /**
     * Records the account's unrecorded transactions of a coin package, or
     * refreshes the training level.
     *
     * @return array{coins: int, level: int}
     */
    public function sync(User $user, string $productId): array
    {
        if (isset(config('purchases.training_products')[$productId])) {
            return ['coins' => 0, 'level' => $this->applyTraining($user, $this->entitlements($user))];
        }
        $coins = (int) (config('purchases.coin_products')[$productId] ?? 0);
        $transactions = collect($this->purchases($user))
            ->filter(fn (array $purchase) => $purchase['product'] === $productId)
            ->sortBy('purchased_at')->values()->all();

        $granted = DB::transaction(function () use ($user, $productId, $coins, $transactions) {
            $granted = 0;
            foreach ($transactions as $transaction) {
                $granted += DB::table('revenuecat_purchases')->insertOrIgnore([
                    'user_id' => $user->id, 'transaction_id' => $transaction['id'], 'product_id' => $productId, 'coins' => $coins,
                    'purchased_at' => $transaction['purchased_at'],
                    'created_at' => now(), 'updated_at' => now(),
                ]) * $coins;
            }
            if ($granted) {
                DB::table('users')->where('id', $user->id)->increment('ai_coins', $granted);
            }

            return $granted;
        });

        return ['coins' => $granted, 'level' => $this->level($user)];
    }

    /** The level ran out on our side: ask RevenueCat whether it renewed. */
    public function refreshTraining(User $user): void
    {
        if ($user->training_level && $user->training_until?->isPast() && config('purchases.secret_api_key')) {
            rescue(fn () => $this->applyTraining($user, $this->entitlements($user)), report: false);
        }
    }

    /**
     * The highest level whose entitlement is active; levels include those below.
     *
     * @param  array<string, ?Carbon>  $entitlements
     */
    private function applyTraining(User $user, array $entitlements): int
    {
        $level = 0;
        $until = null;
        foreach (array_unique(config('purchases.training_products')) as $candidate) {
            if (! array_key_exists('training'.$candidate, $entitlements)) {
                continue;
            }
            $expires = $entitlements['training'.$candidate];
            if (($expires === null || $expires->isFuture()) && $candidate > $level) {
                [$level, $until] = [$candidate, $expires];
            }
        }
        $user->forceFill(['training_level' => $level ?: null, 'training_until' => $level ? $until : null])->save();

        return $level;
    }

    /**
     * The account's one-time store purchases, by store product id.
     *
     * @return list<array{id: string, product: ?string, purchased_at: ?Carbon}>
     */
    private function purchases(User $user): array
    {
        $products = collect($this->list('products'))->pluck('store_identifier', 'id');

        return collect($this->list($this->customer($user).'/purchases'))
            ->filter(fn ($purchase) => ($purchase['status'] ?? null) === 'owned'
                && is_string($purchase['store_purchase_identifier'] ?? null) && $purchase['store_purchase_identifier'] !== '')
            ->map(fn ($purchase) => [
                'id' => $purchase['store_purchase_identifier'],
                'product' => $products[$purchase['product_id'] ?? ''] ?? null,
                'purchased_at' => isset($purchase['purchased_at']) ? Carbon::createFromTimestampMs($purchase['purchased_at']) : null,
            ])->values()->all();
    }

    /**
     * The account's active entitlements: lookup key => when it expires.
     *
     * @return array<string, ?Carbon>
     */
    private function entitlements(User $user): array
    {
        $active = $this->list($this->customer($user).'/active_entitlements');
        if ($active === []) {
            return [];
        }
        $keys = collect($this->list('entitlements'))->pluck('lookup_key', 'id');

        return collect($active)->filter(fn ($entitlement) => isset($keys[$entitlement['entitlement_id'] ?? '']))
            ->mapWithKeys(fn ($entitlement) => [$keys[$entitlement['entitlement_id']] => isset($entitlement['expires_at'])
                ? Carbon::createFromTimestampMs($entitlement['expires_at']) : null])
            ->all();
    }

    private function customer(User $user): string
    {
        return 'customers/'.rawurlencode(config('purchases.app_user_prefix').$user->id);
    }

    /** Every item of a v2 list, following its pages. A customer RevenueCat has never seen has none. */
    private function list(string $path): array
    {
        $key = config('purchases.secret_api_key');
        abort_unless($key, 503, 'Purchases are not configured yet.');
        $url = 'https://api.revenuecat.com/v2/projects/'.rawurlencode(config('purchases.project_id')).'/'.$path;
        $items = [];
        for ($page = 0; $url && $page < 20; $page++) {
            $response = Http::withToken($key)->acceptJson()->timeout(10)->get($url, $page ? [] : ['limit' => 100]);
            if ($response->notFound() && $response->json('type') === 'resource_missing') {
                return [];
            }
            abort_unless($response->successful(), 502, 'The purchase could not be verified.');
            $items = [...$items, ...(array) $response->json('items', [])];
            $url = $response->json('next_page');
        }

        return $items;
    }
}
