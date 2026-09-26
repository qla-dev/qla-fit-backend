<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecordResource extends JsonResource
{
    public static bool $forceWrapping = true;

    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'version' => (int) $this->version,
            'data' => $this->payload === null ? null : json_decode($this->payload, true),
            'deleted' => $this->deleted_at !== null];
    }
}
