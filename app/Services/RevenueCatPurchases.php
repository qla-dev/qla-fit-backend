<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Grants what an account bought in the app, from RevenueCat's own record of
 * it (REST API v1, secret key). Each store transaction is granted once:
 * coin packages add their coins, and a program price unlocks the one
 * program it was bought for. Same flow as putni-nalozi's credit sync.
 */
class RevenueCatPurchases
{
    /** Programs the account has unlocked. */
    public function programs(User $user): array
    {
        return DB::table('revenuecat_purchases')->where('user_id', $user->id)
            ->whereNotNull('program_id')->distinct()->orderBy('program_id')->pluck('program_id')->all();
    }

    /**
     * Records the account's unrecorded transactions of `$productId`.
     *
     * @return array{coins: int, program: ?string}
     */
    public function sync(User $user, string $productId, ?string $programId): array
    {
        $coins = (int) (config('purchases.coin_products')[$productId] ?? 0);
        $transactions = $this->transactions($user, $productId);

        return DB::transaction(function () use ($user, $productId, $programId, $coins, $transactions) {
            $row = fn (array $transaction, array $grant) => [
                'user_id' => $user->id, 'transaction_id' => $transaction['id'], 'product_id' => $productId,
                'purchased_at' => isset($transaction['purchase_date']) ? now()->parse($transaction['purchase_date']) : null,
                'created_at' => now(), 'updated_at' => now(), 'coins' => 0, 'program_id' => null, ...$grant,
            ];
            if ($coins > 0) {
                $granted = 0;
                foreach ($transactions as $transaction) {
                    $granted += DB::table('revenuecat_purchases')->insertOrIgnore($row($transaction, ['coins' => $coins])) * $coins;
                }
                if ($granted) {
                    DB::table('users')->where('id', $user->id)->increment('ai_coins', $granted);
                }

                return ['coins' => $granted, 'program' => null];
            }
            // A program price unlocks one program: the oldest transaction of
            // that price not already spent on another. One the account owns
            // is not unlocked twice.
            if (in_array($programId, $this->programs($user), true)) {
                return ['coins' => 0, 'program' => $programId];
            }
            $used = DB::table('revenuecat_purchases')->where('user_id', $user->id)
                ->where('product_id', $productId)->lockForUpdate()->pluck('transaction_id')->all();
            foreach ($transactions as $transaction) {
                if (! in_array($transaction['id'], $used, true)
                    && DB::table('revenuecat_purchases')->insertOrIgnore($row($transaction, ['program_id' => $programId]))) {
                    return ['coins' => 0, 'program' => $programId];
                }
            }
            abort(402, 'No unused purchase of this price was found. Try again in a moment.');
        });
    }

    /** RevenueCat's transactions of one product for this account, oldest first. */
    private function transactions(User $user, string $productId): array
    {
        $key = config('purchases.secret_api_key');
        abort_unless($key, 503, 'Purchases are not configured yet.');
        $response = Http::withToken($key)->acceptJson()->timeout(10)
            ->get('https://api.revenuecat.com/v1/subscribers/'.rawurlencode(config('purchases.app_user_prefix').$user->id));
        abort_unless($response->successful(), 502, 'The purchase could not be verified.');

        // By key, not by dotted path: product ids are full of dots.
        $purchases = $response->json('subscriber.non_subscriptions') ?? [];

        return collect(is_array($purchases) ? ($purchases[$productId] ?? []) : [])
            ->filter(fn ($transaction) => is_string($transaction['id'] ?? null) && $transaction['id'] !== '')
            ->sortBy('purchase_date')->values()->all();
    }
}
