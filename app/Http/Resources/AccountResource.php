<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'ai_coins' => (int) $this->ai_coins,
            'free_messages' => $this->freeMessages(),
            // The method signed in with first (kept for older app builds),
            // and every method linked to the account.
            'sign_in' => $this->methods()[0] ?? null,
            'sign_in_methods' => $this->methods()];
    }

    private function methods(): array
    {
        $connected = $this->created_at?->toISOString();
        $apple = $this->apple_id ? ['provider' => 'apple', 'email' => $this->apple_email,
            'private_email' => (bool) $this->apple_email_private, 'connected_at' => $connected] : null;
        $google = $this->google_id ? ['provider' => 'google', 'email' => $this->google_email,
            'private_email' => false, 'connected_at' => $connected] : null;

        return array_values(array_filter([$apple, $google]));
    }
}
