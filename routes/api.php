<?php

use App\Http\Controllers\AppleSessionController;
use App\Http\Controllers\MarkaiController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('auth/apple/challenge', [AppleSessionController::class, 'challenge'])->middleware('throttle:20,1');
Route::post('auth/apple', [AppleSessionController::class, 'store'])->middleware('throttle:10,1');
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('account', [AppleSessionController::class, 'show']);
    Route::delete('session', [AppleSessionController::class, 'destroy']);
    Route::apiResource('sync', SyncController::class)->only(['index', 'store']);
    Route::apiResource('records/{collection}/items', RecordController::class)->parameters(['items' => 'record']);
    Route::apiResource('markai/messages', MarkaiController::class)->only(['index', 'store']);
});
