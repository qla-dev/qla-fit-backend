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

    private ?array $record = null;

    private array $entitlements = [];

    /**
     * RevenueCat's record of the account: [product id => [transaction id, …]]
     * and [entitlement => expires date]. Read by one fake, so each call
     * replaces the record the next request sees.
     */
    private function bought(array $products, array $entitlements = []): void
    {
        config(['purchases.secret_api_key' => 'sk_test']);
        $fresh = $this->record === null;
        $this->record = [];
        foreach ($products as $product => $ids) {
            foreach ($ids as $index => $id) {
                $this->record[$product][] = ['id' => $id, 'purchase_date' => "2026-09-30T10:0{$index}:00Z", 'store' => 'app_store'];
            }
        }
        $this->entitlements = collect($entitlements)->map(fn ($expires) => ['expires_date' => $expires])->all();
        if ($fresh) {
            Http::fake(['api.revenuecat.com/*' => fn () => Http::response(['subscriber' => [
                'non_subscriptions' => $this->record, 'entitlements' => $this->entitlements,
            ]])]);
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

    public function test_a_training_level_unlocks_its_programs_and_those_below_while_it_runs(): void
    {
        $user = $this->member();
        $month = now()->addMonth()->toIso8601String();
        // Bought, but RevenueCat has not reported it yet: the app restores.
        $this->bought([]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training2monthly', 'program_id' => 'iron-chest'])
            ->assertStatus(402);

        $this->bought([], ['training1' => $month, 'training2' => $month]);
        $response = $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training2monthly', 'program_id' => 'iron-chest'])
            ->assertOk()->assertJsonPath('data.training_level', 2)->assertJsonPath('data.granted.level', 2);
        $programs = $response->json('data.programs');
        $this->assertContains('iron-chest', $programs);
        $this->assertContains('shred-30', $programs);
        $this->assertNotContains('lean-machine', $programs);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training2monthly', 'program_id' => 'lean-machine'])
            ->assertStatus(402);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training2monthly', 'program_id' => 'nope'])
            ->assertUnprocessable();
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.free'])->assertUnprocessable();

        // Upgrading to level 3 unlocks everything.
        $this->bought([], ['training1' => $month, 'training2' => $month, 'training3' => $month]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training3monthly', 'program_id' => 'lean-machine'])
            ->assertOk()->assertJsonPath('data.programs', collect(config('purchases.programs'))->keys()->sort()->values()->all());

        // It ran out and was not renewed: nothing is unlocked any more.
        $this->travel(2)->months();
        $this->bought([], ['training3' => now()->subMonth()->toIso8601String()]);
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('data.training_level', 0)->assertJsonPath('data.programs', []);
        $this->assertNull($user->fresh()->training_level);
    }

    public function test_a_renewed_level_is_picked_up_when_the_old_one_runs_out(): void
    {
        $this->member();
        $this->bought([], ['training1' => now()->addDay()->toIso8601String()]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training1monthly'])->assertOk();
        $this->travel(2)->days();
        $this->bought([], ['training1' => now()->addMonth()->toIso8601String()]);
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('data.training_level', 1);
    }

    public function test_without_a_key_purchases_are_not_available_and_accounts_are_separate(): void
    {
        $this->member();
        config(['purchases.secret_api_key' => null]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.coins100'])->assertStatus(503);

        $this->bought([], ['training3' => now()->addMonth()->toIso8601String()]);
        $this->postJson('/api/purchases', ['product_id' => 'fitness.qla.dev.training3monthly'])->assertOk();
        $this->member();
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('data.programs', []);
    }

    public function test_every_program_is_sold_at_one_of_the_three_levels(): void
    {
        $this->assertSame([1, 2, 3], array_values(config('purchases.training_products')));
        foreach (config('purchases.programs') as $program => $level) {
            $this->assertContains($level, [1, 2, 3], $program);
        }
    }
}
