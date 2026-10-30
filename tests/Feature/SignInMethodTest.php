<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SignInMethodTest extends TestCase
{
    use RefreshDatabase;

    private \OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (is_file('C:/xampp/php/extras/ssl/openssl.cnf')) {
            $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        }
        $this->key = openssl_pkey_new($options);
        $details = openssl_pkey_get_details($this->key);
        $b64 = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $keys = ['keys' => [['kid' => 'test-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]];
        Http::fake(['appleid.apple.com/auth/keys' => Http::response($keys), 'www.googleapis.com/oauth2/v3/certs' => Http::response($keys)]);
    }

    private function google(string $sub, string $email = 'runner@gmail.com'): array
    {
        return ['id_token' => JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => config('fitness.google_audiences')[0],
            'sub' => $sub, 'email' => $email, 'email_verified' => true, 'iat' => time(), 'exp' => time() + 60], $this->key, 'RS256', 'test-key')];
    }

    private function apple(string $sub): array
    {
        $challenge = $this->postJson('/api/auth/apple/challenge')->json('data');

        return ['challenge_id' => $challenge['id'], 'identity_token' => JWT::encode(['iss' => 'https://appleid.apple.com',
            'aud' => 'fitness.qla.dev', 'sub' => $sub, 'nonce' => $challenge['nonce'], 'email' => 'me@privaterelay.appleid.com',
            'email_verified' => 'true', 'is_private_email' => 'true', 'iat' => time(), 'exp' => time() + 60], $this->key, 'RS256', 'test-key')];
    }

    public function test_google_links_to_the_signed_in_apple_account_and_signs_into_it(): void
    {
        $user = User::factory()->create(['apple_id' => 'apple-1', 'ai_coins' => 42]);
        Sanctum::actingAs($user);

        $this->postJson('/api/account/sign-in-methods/google', $this->google('google-1'))->assertOk()
            ->assertJsonPath('data.sign_in_methods.0.provider', 'apple')
            ->assertJsonPath('data.sign_in_methods.1.provider', 'google')
            ->assertJsonPath('data.sign_in_methods.1.email', 'runner@gmail.com');

        // Signing in with Google now opens the same account, with no new coins.
        $this->postJson('/api/auth/google', $this->google('google-1'))->assertOk()
            ->assertJsonPath('data.registered', false)->assertJsonPath('data.user.id', (string) $user->id)
            ->assertJsonPath('data.user.ai_coins', 42);
        $this->assertSame(1, User::count());
    }

    public function test_apple_links_to_a_google_account(): void
    {
        $user = User::factory()->create(['google_id' => 'google-1']);
        Sanctum::actingAs($user);
        $this->postJson('/api/account/sign-in-methods/apple', $this->apple('apple-1'))->assertOk()
            ->assertJsonPath('data.sign_in_methods.0.provider', 'apple')
            ->assertJsonPath('data.sign_in_methods.0.private_email', true);
        $this->assertSame('apple-1', $user->fresh()->apple_id);
    }

    public function test_an_identity_with_its_own_account_is_refused_not_merged(): void
    {
        User::factory()->create(['google_id' => 'google-taken']);
        $user = User::factory()->create(['apple_id' => 'apple-1']);
        Sanctum::actingAs($user);
        $this->postJson('/api/account/sign-in-methods/google', $this->google('google-taken'))->assertStatus(409);
        $this->assertNull($user->fresh()->google_id);
        // Linking the one it already has is fine.
        $user->forceFill(['google_id' => 'google-mine'])->save();
        $this->postJson('/api/account/sign-in-methods/google', $this->google('google-mine'))->assertOk();
        $this->postJson('/api/account/sign-in-methods/facebook', [])->assertNotFound();
    }

    public function test_a_method_disconnects_only_while_another_remains(): void
    {
        $user = User::factory()->create(['apple_id' => 'apple-1', 'google_id' => 'google-1', 'google_email' => 'runner@gmail.com']);
        Sanctum::actingAs($user);
        $this->deleteJson('/api/account/sign-in-methods/google')->assertOk()
            ->assertJsonCount(1, 'data.sign_in_methods')->assertJsonPath('data.sign_in.provider', 'apple');
        $this->assertNull($user->fresh()->google_email);
        $this->deleteJson('/api/account/sign-in-methods/apple')->assertUnprocessable();
        $this->assertSame('apple-1', $user->fresh()->apple_id);
    }
}
