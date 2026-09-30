<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function member(int $coins = 100): User
    {
        $user = User::factory()->create(['ai_coins' => $coins]);
        Sanctum::actingAs($user);

        return $user;
    }

    private array $record = [];

    /**
     * RevenueCat's record of the account: [product id => [transaction id, …]].
     * Read by one fake, so each call replaces the record the next request sees.
     */
    private function bought(array $products): void
    {
        config(['purchases.secret_api_key' => 'sk_test']);
        $fresh = $this->record === [];
        $this->record = [];
        foreach ($products as $product => $ids) {
            foreach ($ids as $index => $id) {
                $this->record[$product][] = ['id' => $id, 'purchase_date' => "2026-09-30T10:0{$index}:00Z", 'store' => 'app_store'];
            }
        }
        if ($fresh) {
            Http::fake(['api.revenuecat.com/*' => fn () => Http::response(['subscriber' => ['non_subscriptions' => $this->record]])]);
        }
    }

    public function test_a_coin_package_is_granted_once_per_store_transaction(): void
    {
        $user = $this->member();
        $this->bought(['fitness.qla.dev.coins500' => ['t1']]);

        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.coins500'])->assertOk()
            ->assertJsonPath('data.ai_coins', 600)->assertJsonPath('data.granted.coins', 500);
        // Asking again, or restoring, grants nothing new.
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.coins500'])->assertOk()
            ->assertJsonPath('data.ai_coins', 600)->assertJsonPath('data.granted.coins', 0);

        // A second purchase of the same package is a second transaction.
        $this->bought(['fitness.qla.dev.coins500' => ['t1', 't2'], 'fitness.qla.dev.coins100' => ['t3']]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.coins500'])->assertJsonPath('data.ai_coins', 1100);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.coins100'])->assertJsonPath('data.ai_coins', 1200);
        $this->assertSame(1200, $user->fresh()->ai_coins);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/subscribers/qla-fit-user-'.$user->id)
            && $request->hasHeader('Authorization', 'Bearer sk_test'));
    }

    public function test_a_program_price_unlocks_one_program_sold_at_that_price(): void
    {
        $this->member();
        $this->bought(['fitness.qla.dev.program499' => ['p1']]);

        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program499', 'program_id' => 'shred-30'])
            ->assertOk()->assertJsonPath('data.programs', ['shred-30'])->assertJsonPath('data.granted.program', 'shred-30');
        // The same transaction cannot pay for a second program…
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program499', 'program_id' => 'core-of-steel'])
            ->assertStatus(402);
        // …but a program already owned is simply owned.
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program499', 'program_id' => 'shred-30'])
            ->assertOk()->assertJsonPath('data.programs', ['shred-30']);
        // A cheaper price never unlocks a dearer program, nor an unknown one.
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program499', 'program_id' => 'lean-machine'])
            ->assertUnprocessable();
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program499'])->assertUnprocessable();
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.free'])->assertUnprocessable();

        $this->bought(['fitness.qla.dev.program499' => ['p1', 'p2']]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program499', 'program_id' => 'core-of-steel'])
            ->assertOk()->assertJsonPath('data.programs', ['core-of-steel', 'shred-30']);
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('data.programs', ['core-of-steel', 'shred-30']);
    }

    public function test_without_a_key_purchases_are_not_available_and_accounts_are_separate(): void
    {
        $this->member();
        config(['purchases.secret_api_key' => null]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.coins100'])->assertStatus(503);

        $this->bought(['fitness.qla.dev.program1399' => ['x1']]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.program1399', 'program_id' => 'iron-chest'])->assertOk();
        $this->member();
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('data.programs', []);
    }

    public function test_every_program_is_sold_at_one_of_the_three_prices(): void
    {
        $prices = array_keys(config('purchases.program_products'));
        $this->assertSame([4.99, 13.99, 28.99], array_values(config('purchases.program_products')));
        foreach (config('purchases.programs') as $program => $product) {
            $this->assertContains($product, $prices, $program);
        }
    }
}
