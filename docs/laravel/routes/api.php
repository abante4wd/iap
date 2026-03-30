<?php

use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/purchases/verify', [PurchaseController::class, 'verify']);
});

// ストアからのサーバー通知（認証はストア側の署名検証で行う）
Route::post('/notifications/apple', [NotificationController::class, 'apple']);
Route::post('/notifications/google', [NotificationController::class, 'google']);
