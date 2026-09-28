<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'ai_coins' => (int) $this->ai_coins,
            'sign_in' => ['provider' => 'apple', 'email' => $this->apple_email,
                'private_email' => (bool) $this->apple_email_private,
                'connected_at' => $this->created_at?->toISOString()]];
    }
}
