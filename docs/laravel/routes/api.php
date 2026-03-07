<?php

use App\Http\Controllers\PurchaseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/purchases/verify', [PurchaseController::class, 'verify']);
});
