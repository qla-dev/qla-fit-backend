<?php

namespace App\Services;

use App\Http\Resources\RecordResource;
use Illuminate\Support\Facades\DB;

class FitnessRecords
{
    public function query(int $user, string $collection)
    {
        abort_unless(in_array($collection, config('fitness.collections'), true), 404);

        return DB::table($collection)->where('account_id', $user);
    }

    public function snapshot(int $user): array
    {
        $tables = [];
        foreach (config('fitness.collections') as $name) {
            $tables[$name] = RecordResource::collection($this->query($user, $name)->get())->resolve();
        }

        return $tables;
    }

    public function write(int $user, string $collection, array $change): void
    {
        $query = $this->query($user, $collection)->where('id', $change['id']);
        $old = $query->first();
        abort_if((int) ($old->version ?? 0) !== $change['base_version'], 409,
            'This record changed on another device. Review the sync conflict before retrying.');
        $payload = $change['data'] ?? null;
        if ($payload !== null) {
            abort_if(isset($payload['id']) && (string) $payload['id'] !== $change['id'], 422, 'Record ID does not match.');
            $payload['id'] ??= $change['id'];
        }
        DB::table($collection)->updateOrInsert(['account_id' => $user, 'id' => $change['id']], [
            'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'version' => (int) ($old->version ?? 0) + 1,
            'deleted_at' => $payload === null ? now() : null,
            'created_at' => $old->created_at ?? now(), 'updated_at' => now(),
        ]);
    }
}
