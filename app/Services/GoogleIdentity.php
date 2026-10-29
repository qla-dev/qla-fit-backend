<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Verifies the ID token the app gets from Google Sign-In, as AppleIdentity does for Apple. */
class GoogleIdentity
{
    public function verify(string $token): object
    {
        try {
            $keys = Cache::remember('google-signing-keys', 3600, fn () => Http::timeout(10)->get('https://www.googleapis.com/oauth2/v3/certs')->throw()->json());
            $claims = JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
            if (! in_array($claims->iss ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
                || ! in_array($claims->aud ?? '', config('fitness.google_audiences'), true)
                || ! is_string($claims->sub ?? null) || $claims->sub === ''
                || ($claims->exp ?? 0) <= time()) {
                throw new \RuntimeException('Invalid claims');
            }

            return $claims;
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['id_token' => 'Google sign-in could not be verified. Please try again.']);
        }
    }
}
