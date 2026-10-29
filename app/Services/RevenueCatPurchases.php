<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Grants what an account bought in the app, from RevenueCat's own record of
 * it (REST API v1, secret key). Each coin transaction is granted once; the
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
        $subscriber = $this->subscriber($user);
        if (isset(config('purchases.training_products')[$productId])) {
            return ['coins' => 0, 'level' => $this->applyTraining($user, $subscriber)];
        }
        $coins = (int) (config('purchases.coin_products')[$productId] ?? 0);
        // By key, not by dotted path: product ids are full of dots.
        $purchases = $subscriber['non_subscriptions'] ?? [];
        $transactions = collect(is_array($purchases) ? ($purchases[$productId] ?? []) : [])
            ->filter(fn ($transaction) => is_string($transaction['id'] ?? null) && $transaction['id'] !== '')
            ->sortBy('purchase_date')->values()->all();

        $granted = DB::transaction(function () use ($user, $productId, $coins, $transactions) {
            $granted = 0;
            foreach ($transactions as $transaction) {
                $granted += DB::table('revenuecat_purchases')->insertOrIgnore([
                    'user_id' => $user->id, 'transaction_id' => $transaction['id'], 'product_id' => $productId, 'coins' => $coins,
                    'purchased_at' => isset($transaction['purchase_date']) ? Carbon::parse($transaction['purchase_date']) : null,
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
            rescue(fn () => $this->applyTraining($user, $this->subscriber($user)), report: false);
        }
    }

    /** The highest level whose entitlement is active; levels include those below. */
    private function applyTraining(User $user, array $subscriber): int
    {
        $level = 0;
        $until = null;
        foreach (array_unique(config('purchases.training_products')) as $candidate) {
            $entitlement = $subscriber['entitlements']['training'.$candidate] ?? null;
            if (! is_array($entitlement)) {
                continue;
            }
            $expires = is_string($entitlement['expires_date'] ?? null) ? Carbon::parse($entitlement['expires_date']) : null;
            if (($expires === null || $expires->isFuture()) && $candidate > $level) {
                [$level, $until] = [$candidate, $expires];
            }
        }
        $user->forceFill(['training_level' => $level ?: null, 'training_until' => $level ? $until : null])->save();

        return $level;
    }

    private function subscriber(User $user): array
    {
        $key = config('purchases.secret_api_key');
        abort_unless($key, 503, 'Purchases are not configured yet.');
        $response = Http::withToken($key)->acceptJson()->timeout(10)
            ->get('https://api.revenuecat.com/v1/subscribers/'.rawurlencode(config('purchases.app_user_prefix').$user->id));
        abort_unless($response->successful(), 502, 'The purchase could not be verified.');

        return (array) ($response->json('subscriber') ?? []);
    }
}
