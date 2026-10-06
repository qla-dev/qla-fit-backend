<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppleIdentity;
use App\Services\Markai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_health_tracking_collections_sync(): void
    {
        $this->member();
        $changes = collect(['medications', 'medicationEntries', 'cycleLogs', 'cycles', 'pregnancies', 'pregnancyPhotos'])
            ->map(fn ($collection) => ['collection' => $collection, 'id' => $collection.'-1', 'base_version' => 0, 'data' => ['id' => $collection.'-1']])
            ->all();
        $this->postJson('/api/sync', ['request_id' => (string) Str::uuid(), 'changes' => $changes])->assertOk()
            ->assertJsonPath('data.tables.medications.0.id', 'medications-1')
            ->assertJsonPath('data.tables.pregnancyPhotos.0.version', 1);
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

    public function test_ai_replies_carry_a_proposed_calorie_goal_or_none(): void
    {
        config(['fitness.markai.key' => 'test']);
        $answers = [
            ['text' => '2 000 × 0.85 ≈ 1 700 kcal.', 'food' => null, 'goal' => ['key' => 'calories', 'value' => 1700]],
            // Older replies and models that leave goal out.
            ['text' => 'Hello!', 'food' => null],
        ];
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode($answers[0])]]]])
            ->push(['choices' => [['message' => ['content' => json_encode($answers[1])]]]])]);
        $ai = app(Markai::class);

        $this->assertSame(['key' => 'calories', 'value' => 1700], $ai->reply('free', [])['goal']);
        $this->assertNull($ai->reply('free', [])['goal']);
    }

    public function test_moving_help_suggests_one_startable_session_and_other_modes_none(): void
    {
        config(['fitness.markai.key' => 'test']);
        $reply = fn ($workout) => ['choices' => [['message' => ['content' => json_encode(['text' => 'Try an easy ride.', 'food' => null, 'goal' => null, 'workout' => $workout])]]]];
        $ride = ['sport' => 'ride', 'goal' => 'time', 'value' => 30];
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push($reply($ride))
            ->push($reply($ride))
            ->push($reply(['sport' => 'run', 'goal' => 'distance', 'value' => 900]))]);
        $ai = app(Markai::class);

        $this->assertSame($ride, $ai->reply('training', [])['workout']);
        // A session in another mode is dropped rather than shown.
        $this->assertNull($ai->reply('free', [])['workout']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $ai->reply('training', []);
    }

    public function test_carbs_and_fat_can_each_be_proposed_on_their_own_within_range(): void
    {
        config(['fitness.markai.key' => 'test']);
        $reply = fn ($goal) => ['choices' => [['message' => ['content' => json_encode(['text' => 'Worked out.', 'food' => null, 'goal' => $goal])]]]];
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push($reply(['key' => 'carbs', 'value' => 260]))
            ->push($reply(['key' => 'fat', 'value' => 80]))
            ->push($reply(['key' => 'fat', 'value' => 900]))]);
        $ai = app(Markai::class);

        $this->assertSame(['key' => 'carbs', 'value' => 260], $ai->reply('free', [])['goal']);
        $this->assertSame(['key' => 'fat', 'value' => 80], $ai->reply('free', [])['goal']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $ai->reply('free', []);
    }

    public function test_a_full_macro_plan_costs_ten_coins_and_is_refunded_on_failure(): void
    {
        $user = $this->member(15);
        $plan = ['key' => 'macros', 'values' => ['calories' => 2200, 'protein' => 150, 'carbs' => 240, 'fat' => 70]];
        $this->mock(Markai::class, fn ($mock) => $mock->shouldReceive('reply')
            ->withArgs(fn ($mode, $messages, $task) => $task === 'all_macros')
            ->once()->andReturn(['text' => 'TDEE 2 750 × 0.8 …', 'food' => null, 'goal' => $plan]));
        $body = ['id' => (string) Str::uuid(), 'conversation_id' => (string) Str::uuid(), 'mode' => 'free',
            'prompt' => 'Work out all my macros', 'task' => 'all_macros'];

        $this->postJson('/api/markai/messages', $body)->assertOk()
            ->assertJsonPath('data.reply.goal', $plan)->assertJsonPath('data.ai_coins', 5);
        // A retry of the same request is the same plan, charged once.
        $this->postJson('/api/markai/messages', $body)->assertOk();
        $this->assertSame(5, $user->fresh()->ai_coins);
        // Five coins left is not enough for another.
        $this->postJson('/api/markai/messages', [...$body, 'id' => (string) Str::uuid()])
            ->assertStatus(402)->assertJsonPath('message', 'This needs 10 AI coins.');
        $this->postJson('/api/markai/messages', [...$body, 'id' => (string) Str::uuid(), 'task' => 'free_money'])
            ->assertUnprocessable();

        $this->member(20);
        $this->mock(Markai::class, fn ($mock) => $mock->shouldReceive('reply')->andThrow(new \RuntimeException('down')));
        $this->postJson('/api/markai/messages', [...$body, 'id' => (string) Str::uuid()])->assertStatus(503);
        $this->assertSame(20, User::latest('id')->first()->ai_coins);
    }

    public function test_the_macro_plan_must_come_back_as_all_four_macros(): void
    {
        config(['fitness.markai.key' => 'test']);
        $answer = fn (array $reply) => ['choices' => [['message' => ['content' => json_encode($reply)]]]];
        $plan = ['key' => 'macros', 'values' => ['calories' => 2200, 'protein' => 150, 'carbs' => 240, 'fat' => 70]];
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push($answer(['text' => 'Plan.', 'food' => null, 'goal' => $plan]))
            ->push($answer(['text' => 'Protein only.', 'food' => null, 'goal' => ['key' => 'protein', 'value' => 150]]))
            ->push($answer(['text' => 'Protein.', 'food' => null, 'goal' => ['key' => 'protein', 'value' => 140]]))]);
        $ai = app(Markai::class);

        $this->assertSame($plan, $ai->reply('free', [], 'all_macros')['goal']);
        try {
            $ai->reply('free', [], 'all_macros');
            $this->fail('A paid plan without all four macros was accepted.');
        } catch (ValidationException) {
        }
        // Outside the plan, a protein goal on its own is fine.
        $this->assertSame(['key' => 'protein', 'value' => 140], $ai->reply('free', [])['goal']);
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

    public function test_a_labelled_prompt_reaches_the_model_but_history_shows_the_label(): void
    {
        $this->member();
        $prompt = 'Work out my daily calorie goal from my details below. Age: 30';
        $this->mock(Markai::class, fn ($mock) => $mock->shouldReceive('reply')->once()
            ->withArgs(fn ($mode, $messages) => end($messages)['content'] === $prompt)
            ->andReturn(['text' => 'About 2,400 kcal.', 'food' => null]));
        $conversation = (string) Str::uuid();
        $this->postJson('/api/markai/messages', ['id' => (string) Str::uuid(), 'conversation_id' => $conversation,
            'mode' => 'free', 'prompt' => $prompt, 'label' => 'Work out my calorie goal'])->assertOk();
        $this->getJson('/api/markai/messages')->assertOk()->assertJsonPath('data.0.title', 'Work out my calorie goal');
        $this->getJson('/api/markai/messages?conversation_id='.$conversation)->assertOk()
            ->assertJsonPath('data.0.label', 'Work out my calorie goal')->assertJsonPath('data.0.prompt', $prompt);
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

    public function test_apple_sign_in_shows_the_verified_email_without_using_it_as_identity(): void
    {
        $claims = [
            (object) ['sub' => 'apple-user', 'email' => 'x7@privaterelay.appleid.com', 'email_verified' => 'true', 'is_private_email' => 'true'],
            (object) ['sub' => 'apple-user', 'email' => 'nedim@example.com', 'email_verified' => true],
            (object) ['sub' => 'other-user', 'email' => 'nedim@example.com', 'email_verified' => false],
        ];
        $this->mock(AppleIdentity::class, fn ($mock) => $mock->shouldReceive('verify')->times(3)->andReturn(...$claims));
        $signIn = fn () => $this->postJson('/api/auth/apple', ['identity_token' => 't',
            'challenge_id' => $this->postJson('/api/auth/apple/challenge')->json('data.id')]);
        $signIn()->assertOk()->assertJsonPath('data.user.sign_in.email', 'x7@privaterelay.appleid.com')
            ->assertJsonPath('data.user.sign_in.private_email', true)->assertJsonPath('data.user.sign_in.provider', 'apple');
        // Refreshed on the next sign-in; the same Apple subject stays one account.
        $signIn()->assertOk()->assertJsonPath('data.user.sign_in.email', 'nedim@example.com')
            ->assertJsonPath('data.user.sign_in.private_email', false);
        // An unverified email is never stored, and never merges accounts.
        $signIn()->assertOk()->assertJsonPath('data.user.sign_in.email', null)->assertJsonPath('data.registered', true);
        $this->assertDatabaseCount('users', 2);
    }
}
