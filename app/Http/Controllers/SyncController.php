<?php

namespace App\Http\Controllers;

use App\Services\FitnessRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    public function index(Request $request, FitnessRecords $records)
    {
        return response()->json(['data' => ['tables' => $records->snapshot($request->user()->id)]]);
    }

    public function store(Request $request, FitnessRecords $records)
    {
        $data = $request->validate([
            'request_id' => 'required|uuid', 'changes' => 'present|array|max:500',
            'changes.*.collection' => ['required', Rule::in(config('fitness.collections'))],
            'changes.*.id' => 'required|string|max:100',
            'changes.*.base_version' => 'required|integer|min:0',
            'changes.*.data' => 'present|nullable|array',
        ]);
        $user = $request->user()->id;
        DB::transaction(function () use ($data, $user, $records) {
            $key = ['user_id' => $user, 'request_id' => $data['request_id']];
            $hash = hash('sha256', json_encode($data['changes']));
            $previous = DB::table('sync_requests')->where($key)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->fingerprint, $hash), 409, 'Request ID was reused.');

                return;
            }
            foreach ($data['changes'] as $change) {
                $records->write($user, $change['collection'], $change);
            }
            DB::table('sync_requests')->insert([...$key, 'fingerprint' => $hash]);
        });

        return $this->index($request, $records);
    }
}
