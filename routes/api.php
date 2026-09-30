<?php

use App\Http\Controllers\AppleSessionController;
use App\Http\Controllers\MarkaiController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('auth/apple/challenge', [AppleSessionController::class, 'challenge'])->middleware('throttle:20,1');
Route::post('auth/apple', [AppleSessionController::class, 'store'])->middleware('throttle:10,1');
// Public shelf prices: a grocery list compares stores without an account.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('prices/vendors', [PriceController::class, 'vendors']);
    Route::post('prices/compare', [PriceController::class, 'compare']);
});
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('account', [AppleSessionController::class, 'show']);
    Route::delete('session', [AppleSessionController::class, 'destroy']);
    Route::apiResource('sync', SyncController::class)->only(['index', 'store']);
    Route::apiResource('records/{collection}/items', RecordController::class)->parameters(['items' => 'record']);
    Route::apiResource('markai/messages', MarkaiController::class)->only(['index', 'store']);
    Route::post('markai/meal-plans', [MealPlanController::class, 'store'])->middleware('throttle:10,1');
});
