<?php

namespace Tests\Feature;

use App\Services\AppleIdentity;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppleIdentityTest extends TestCase
{
    public function test_signed_identity_requires_correct_audience_nonce_and_expiry(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if (is_file('C:/xampp/php/extras/ssl/openssl.cnf')) {
            $options['config'] = 'C:/xampp/php/extras/ssl/openssl.cnf';
        }
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $b64 = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        Http::fake(['appleid.apple.com/auth/keys' => Http::response(['keys' => [[
            'kid' => 'test-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]])]);
        $claims = ['iss' => 'https://appleid.apple.com', 'aud' => 'fitness.qla.dev', 'sub' => 'verified-user', 'nonce' => 'server-nonce', 'iat' => time(), 'exp' => time() + 60];
        $identity = new AppleIdentity;
        $this->assertSame('verified-user', $identity->verify(JWT::encode($claims, $key, 'RS256', 'test-key'), 'server-nonce')->sub);
        foreach ([['aud' => 'another-app'], ['nonce' => 'wrong-nonce'], ['exp' => time() - 60], ['iss' => 'https://attacker.example']] as $invalid) {
            try {
                $identity->verify(JWT::encode([...$claims, ...$invalid], $key, 'RS256', 'test-key'), 'server-nonce');
                $this->fail('Invalid identity was accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
