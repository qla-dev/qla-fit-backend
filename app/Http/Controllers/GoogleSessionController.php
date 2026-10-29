<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Services\GoogleIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sign in with Google: the same session Apple sign-in gives, keyed on Google's subject. */
class GoogleSessionController extends Controller
{
    public function store(Request $request, GoogleIdentity $identity)
    {
        $data = $request->validate(['id_token' => 'required|string|max:16000']);
        $claims = $identity->verify($data['id_token']);
        // Google subject is the identity. Never attach an account using an email.
        $user = DB::transaction(fn () => User::firstOrCreate(['google_id' => $claims->sub], [
            'name' => (is_string($claims->name ?? null) && $claims->name !== '') ? $claims->name : 'qla.fit member',
            'email' => hash('sha256', 'google:'.$claims->sub).'@google.local',
            'password' => Str::random(64), 'ai_coins' => config('fitness.registration_coins'),
        ]));
        $verified = in_array($claims->email_verified ?? false, [true, 'true'], true);
        if (is_string($claims->email ?? null) && $verified) {
            $user->forceFill(['google_email' => $claims->email])->save();
        }

        return response()->json(['data' => [
            'token' => $user->createToken('native', ['*'], now()->addDays(90))->plainTextToken,
            'user' => new AccountResource($user), 'registered' => $user->wasRecentlyCreated,
        ]]);
    }
}
