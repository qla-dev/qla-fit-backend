<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_google_token_signs_in_and_others_are_refused(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (is_file('C:/xampp/php/extras/ssl/openssl.cnf')) {
            $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        }
        $key = openssl_pkey_new($options);
        $details = openssl_pkey_get_details($key);
        $b64 = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        Http::fake(['www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
            'kid' => 'test-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]])]);
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => config('fitness.google_audiences')[0], 'sub' => 'google-user',
            'email' => 'runner@example.com', 'email_verified' => true, 'name' => 'Runner', 'iat' => time(), 'exp' => time() + 60];
        $token = fn (array $override = []) => JWT::encode([...$claims, ...$override], $key, 'RS256', 'test-key');

        $this->postJson('/api/auth/google', ['id_token' => $token()])->assertOk()
            ->assertJsonPath('data.registered', true)->assertJsonPath('data.user.name', 'Runner')
            ->assertJsonPath('data.user.ai_coins', config('fitness.registration_coins'))
            ->assertJsonPath('data.user.sign_in.provider', 'google')->assertJsonPath('data.user.sign_in.email', 'runner@example.com');
        // Signing in again is the same account, with no second coin grant.
        $this->postJson('/api/auth/google', ['id_token' => $token()])->assertOk()->assertJsonPath('data.registered', false);
        $this->assertSame(1, User::count());

        foreach ([['aud' => 'another-app'], ['exp' => time() - 60], ['iss' => 'https://attacker.example'], ['sub' => '']] as $invalid) {
            $this->postJson('/api/auth/google', ['id_token' => $token($invalid)])->assertUnprocessable();
        }
    }
}
