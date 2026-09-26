<?php

namespace App\Http\Controllers;

use App\Http\Resources\RecordResource;
use App\Services\FitnessRecords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordController extends Controller
{
    public function index(Request $request, string $collection, FitnessRecords $records)
    {
        return RecordResource::collection($records->query($request->user()->id, $collection)->whereNull('deleted_at')->paginate(100));
    }

    public function show(Request $request, string $collection, string $record, FitnessRecords $records)
    {
        return new RecordResource($records->query($request->user()->id, $collection)->where('id', $record)->firstOrFail());
    }

    public function store(Request $request, string $collection, FitnessRecords $records)
    {
        $data = $request->validate(['id' => 'required|string|max:100', 'data' => 'required|array']);
        DB::transaction(fn () => $records->write($request->user()->id, $collection, [...$data, 'base_version' => 0]));

        return $this->show($request, $collection, $data['id'], $records);
    }

    public function update(Request $request, string $collection, string $record, FitnessRecords $records)
    {
        $data = $request->validate(['base_version' => 'required|integer|min:1', 'data' => 'required|array']);
        DB::transaction(fn () => $records->write($request->user()->id, $collection, [...$data, 'id' => $record]));

        return $this->show($request, $collection, $record, $records);
    }

    public function destroy(Request $request, string $collection, string $record, FitnessRecords $records)
    {
        $data = $request->validate(['base_version' => 'required|integer|min:1']);
        DB::transaction(fn () => $records->write($request->user()->id, $collection, [...$data, 'id' => $record, 'data' => null]));

        return response()->noContent();
    }
}
