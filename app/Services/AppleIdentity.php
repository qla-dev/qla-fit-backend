<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AppleIdentity
{
    public function verify(string $token, string $nonce): object
    {
        try {
            $keys = Cache::remember('apple-signing-keys', 3600, fn () => Http::timeout(10)->get('https://appleid.apple.com/auth/keys')->throw()->json());
            $claims = JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
            if (($claims->iss ?? '') !== 'https://appleid.apple.com'
                || ! in_array($claims->aud ?? '', config('fitness.apple_audiences'), true)
                || ! is_string($claims->sub ?? null) || $claims->sub === ''
                || ! hash_equals($nonce, $claims->nonce ?? '')
                || ($claims->exp ?? 0) <= time()) {
                throw new \RuntimeException('Invalid claims');
            }

            return $claims;
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['identity_token' => 'Apple sign-in could not be verified. Please try again.']);
        }
    }
}
