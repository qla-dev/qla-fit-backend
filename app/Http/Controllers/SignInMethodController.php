<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\AppleIdentity;
use App\Services\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Sign-in methods of the signed-in account: link Apple or Google to it, or
 * disconnect one while another remains. A provider identity already owning
 * another account is refused, never merged.
 */
class SignInMethodController extends Controller
{
    public function store(Request $request, string $provider, AppleIdentity $apple, GoogleIdentity $google)
    {
        $user = $request->user();
        if ($provider === 'apple') {
            $data = $request->validate(['identity_token' => 'required|string|max:16000', 'challenge_id' => 'required|uuid']);
            $nonce = Cache::lock('apple-challenge-lock:'.$data['challenge_id'], 10)
                ->block(3, fn () => Cache::pull('apple-challenge:'.$data['challenge_id']));
            abort_unless(is_string($nonce), 422, 'Sign-in expired. Please try again.');
            $claims = $apple->verify($data['identity_token'], $nonce);
            $this->claim($user, 'apple_id', $claims->sub, 'Apple');
            if (is_string($claims->email ?? null) && in_array($claims->email_verified ?? false, [true, 'true'], true)) {
                $user->forceFill([
                    'apple_email' => $claims->email,
                    'apple_email_private' => in_array($claims->is_private_email ?? false, [true, 'true'], true),
                ]);
            }
        } else {
            $data = $request->validate(['id_token' => 'required|string|max:16000']);
            $claims = $google->verify($data['id_token']);
            $this->claim($user, 'google_id', $claims->sub, 'Google');
            if (is_string($claims->email ?? null) && in_array($claims->email_verified ?? false, [true, 'true'], true)) {
                $user->forceFill(['google_email' => $claims->email]);
            }
        }
        $user->save();

        return new AccountResource($user->fresh());
    }

    public function destroy(Request $request, string $provider)
    {
        $user = $request->user();
        abort_unless($user->apple_id && $user->google_id, 422, 'This is your only sign-in method. Sign out instead.');
        $user->forceFill($provider === 'apple'
            ? ['apple_id' => null, 'apple_email' => null, 'apple_email_private' => false]
            : ['google_id' => null, 'google_email' => null])->save();

        return new AccountResource($user->fresh());
    }

    private function claim(User $user, string $column, string $subject, string $name): void
    {
        $owner = User::where($column, $subject)->value('id');
        abort_if($owner !== null && $owner !== $user->id, 409,
            "This {$name} account already has its own qla.fit account. Sign in with it to use that one.");
        $user->forceFill([$column => $subject]);
    }
}
