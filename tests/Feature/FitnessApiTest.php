<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppleIdentity;
use App\Services\Markai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FitnessApiTest extends TestCase
{
    use RefreshDatabase;

    private function member(int $coins = 100): User
    {
        $user = User::factory()->create(['ai_coins' => $coins]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function syncChange(string $id, int $version = 0, ?array $data = ['name' => 'Apple']): array
    {
        return ['collection' => 'foods', 'id' => $id, 'base_version' => $version, 'data' => $data];
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/sync')->assertUnauthorized();
    }

    public function test_conversation_history_is_grouped_and_account_scoped(): void
    {
        $user = $this->member();
        $other = User::factory()->create();
        $conversation = (string) Str::uuid();
        foreach ([$user->id, $user->id, $other->id] as $index => $userId) {
            DB::table('markai_messages')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $userId,
                'conversation_id' => $conversation, 'mode' => 'training',
                'prompt' => $index === 0 ? 'First question' : 'Later question',
                'status' => 'complete', 'reply' => json_encode(['text' => 'Answer']),
                'created_at' => now()->addSeconds($index), 'updated_at' => now(),
            ]);
        }
        $this->getJson('/api/markai/messages')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'First question')
            ->assertJsonPath('data.0.message_count', 2);
        $this->getJson('/api/markai/messages?conversation_id='.$conversation)
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_sync_is_idempotent_isolated_and_preserves_deletions(): void
    {
        $user = $this->member();
        $payload = ['request_id' => (string) Str::uuid(), 'changes' => [$this->syncChange('food-1')]];
        $this->postJson('/api/sync', $payload)->assertOk()->assertJsonPath('data.tables.foods.0.version', 1);
        $this->postJson('/api/sync', $payload)->assertOk()->assertJsonPath('data.tables.foods.0.version', 1);
        $this->postJson('/api/sync', ['request_id' => (string) Str::uuid(), 'changes' => [$this->syncChange('food-1', 1, null)]])->assertOk()->assertJsonPath('data.tables.foods.0.deleted', true);
        $this->member();
        $this->getJson('/api/sync')->assertOk()->assertJsonCount(0, 'data.tables.foods');
        $this->assertDatabaseCount('foods', 1);
    }

    public function test_conflict_rolls_back_entire_batch(): void
    {
        $this->member();
        $this->postJson('/api/sync', ['request_id' => (string) Str::uuid(), 'changes' => [$this->syncChange('old')]])->assertOk();
        $this->postJson('/api/sync', ['request_id' => (string) Str::uuid(), 'changes' => [$this->syncChange('new'), $this->syncChange('old')]])->assertConflict();
        $this->assertDatabaseCount('foods', 1);
        $this->postJson('/api/sync', ['request_id' => (string) Str::uuid(), 'changes' => [['collection' => 'users', 'id' => '1', 'base_version' => 0, 'data' => ['ai_coins' => 999]]]])->assertUnprocessable();
    }

    public function test_resource_routes_crud(): void
    {
        $this->member();
        $this->postJson('/api/records/foods/items', ['id' => 'a', 'data' => ['name' => 'Oats']])->assertSuccessful();
        $this->getJson('/api/records/foods/items/a')->assertJsonPath('data.data.name', 'Oats');
        $this->putJson('/api/records/foods/items/a', ['base_version' => 1, 'data' => ['name' => 'Rice']])->assertOk();
        $this->deleteJson('/api/records/foods/items/a', ['base_version' => 2])->assertNoContent();
        $this->getJson('/api/records/foods/items')->assertJsonCount(0, 'data');
    }

    public function test_apple_registration_rewards_only_once_and_challenge_is_single_use(): void
    {
        $this->mock(AppleIdentity::class, fn ($mock) => $mock->shouldReceive('verify')->twice()->andReturn((object) ['sub' => 'apple-user']));
        $id = $this->postJson('/api/auth/apple/challenge')->json('data.id');
        $body = ['identity_token' => 'verified-by-test', 'challenge_id' => $id];
        $this->postJson('/api/auth/apple', $body)->assertOk()->assertJsonPath('data.user.ai_coins', 100)->assertJsonPath('data.registered', true);
        $this->postJson('/api/auth/apple', $body)->assertUnprocessable();
        DB::table('users')->update(['ai_coins' => 42]);
        $body['challenge_id'] = $this->postJson('/api/auth/apple/challenge')->json('data.id');
        $this->postJson('/api/auth/apple', $body)->assertOk()->assertJsonPath('data.user.ai_coins', 42)->assertJsonPath('data.registered', false);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_ai_retry_charges_once_and_confirmation_returns_same_food_card_for_free(): void
    {
        $user = $this->member();
        $food = ['name' => 'Banana', 'serving' => '1 medium', 'calories' => 105, 'protein' => 1, 'carbs' => 27, 'fat' => 0.4];
        $this->mock(Markai::class, fn ($mock) => $mock->shouldReceive('reply')->once()->andReturn(['text' => 'Estimated macros.', 'food' => $food]));
        $body = ['id' => (string) Str::uuid(), 'conversation_id' => (string) Str::uuid(), 'mode' => 'macros', 'prompt' => 'A medium banana'];
        $this->postJson('/api/markai/messages', $body)->assertOk()->assertJsonPath('data.ai_coins', 99);
        $this->postJson('/api/markai/messages', $body)->assertOk();
        $this->assertSame(99, $user->fresh()->ai_coins);
        $this->postJson('/api/markai/messages', [...$body, 'id' => (string) Str::uuid(), 'prompt' => "okay, let's log"])->assertOk()->assertJsonPath('data.reply.log_requested', true)->assertJsonPath('data.reply.food_id', $body['id'])->assertJsonPath('data.ai_coins', 99);
    }

    public function test_ai_failures_refund_and_zero_balance_cannot_spend(): void
    {
        $user = $this->member(1);
        $this->mock(Markai::class, fn ($mock) => $mock->shouldReceive('reply')->once()->andThrow(new \RuntimeException('Provider down')));
        $body = ['id' => (string) Str::uuid(), 'conversation_id' => (string) Str::uuid(), 'mode' => 'free', 'prompt' => 'Hi'];
        $this->postJson('/api/markai/messages', $body)->assertStatus(503);
        $this->assertSame(1, $user->fresh()->ai_coins);
        $this->member(0);
        $this->postJson('/api/markai/messages', [...$body, 'id' => (string) Str::uuid()])->assertStatus(402);
    }

    public function test_ai_photo_messages_reach_the_model_but_are_not_stored(): void
    {
        $this->member();
        $image = 'data:image/jpeg;base64,'.base64_encode('jpeg-bytes');
        $this->mock(Markai::class, fn ($mock) => $mock->shouldReceive('reply')->once()
            ->withArgs(fn ($mode, $messages) => end($messages)['content'][1] === ['type' => 'image_url', 'image_url' => ['url' => $image]]
                && end($messages)['content'][0]['text'] === 'What is in this photo?')
            ->andReturn(['text' => 'A plate of pasta.', 'food' => null]));
        $conversation = (string) Str::uuid();
        $body = ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'mode' => 'free', 'image' => $image];
        $this->postJson('/api/markai/messages', $body)->assertOk()->assertJsonPath('data.ai_coins', 99);
        $this->assertDatabaseHas('markai_messages', ['id' => $body['id'], 'prompt' => '', 'has_image' => true]);
        $this->assertStringNotContainsString('jpeg', json_encode(DB::table('markai_messages')->get()));
        $this->getJson('/api/markai/messages?conversation_id='.$conversation)->assertOk()->assertJsonPath('data.0.has_image', true);
        $this->postJson('/api/markai/messages', [...$body, 'id' => (string) Str::uuid(), 'image' => 'data:text/html;base64,PGI+'])->assertStatus(422);
        $this->postJson('/api/markai/messages', ['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'mode' => 'free'])->assertStatus(422);
    }
}
