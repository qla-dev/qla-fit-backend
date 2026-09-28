<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\AppleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppleSessionController extends Controller
{
    public function challenge()
    {
        $id = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));
        Cache::put('apple-challenge:'.$id, $nonce, 300);

        return response()->json(['data' => ['id' => $id, 'nonce' => $nonce]]);
    }

    public function store(Request $request, AppleIdentity $identity)
    {
        $data = $request->validate(['identity_token' => 'required|string|max:16000',
            'challenge_id' => 'required|uuid', 'name' => 'nullable|string|max:255']);
        $nonce = Cache::lock('apple-challenge-lock:'.$data['challenge_id'], 10)
            ->block(3, fn () => Cache::pull('apple-challenge:'.$data['challenge_id']));
        abort_unless(is_string($nonce), 422, 'Sign-in expired. Please try again.');
        $claims = $identity->verify($data['identity_token'], $nonce);
        // Apple subject is the identity. Never attach an account using an unverified email/name.
        $user = DB::transaction(fn () => User::firstOrCreate(['apple_id' => $claims->sub], [
            'name' => ($data['name'] ?? null) ?: 'qla.fit member',
            'email' => hash('sha256', $claims->sub).'@apple.local',
            'password' => Str::random(64), 'ai_coins' => config('fitness.registration_coins'),
        ]));
        // Shown on the Account screen only. Refreshed on every sign-in, since
        // Apple sends it in each token and a user can change it.
        $verified = in_array($claims->email_verified ?? false, [true, 'true'], true);
        if (is_string($claims->email ?? null) && $verified) {
            $user->forceFill([
                'apple_email' => $claims->email,
                'apple_email_private' => in_array($claims->is_private_email ?? false, [true, 'true'], true),
            ])->save();
        }

        return response()->json(['data' => [
            'token' => $user->createToken('native', ['*'], now()->addDays(90))->plainTextToken,
            'user' => new AccountResource($user), 'registered' => $user->wasRecentlyCreated,
        ]]);
    }

    public function show(Request $request)
    {
        return new AccountResource($request->user());
    }

    public function destroy(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
