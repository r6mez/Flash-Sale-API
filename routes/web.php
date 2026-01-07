<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () { 
    return response()->json([
        'status' => 'ok',
        'redis' => Redis::ping() ? 'connected' : 'disconnected',
        'database' => DB::connection()->getPdo() ? 'connected' : 'disconnected',
    ]);
});

// Route::middleware(['throttle:view'])->group(function () {
    Route::get('/api/products/{id}', [ProductController::class, 'show']);
// });

// Route::middleware(['throttle:critical'])->group(function () {
    Route::post('/api/orders', [OrderController::class, 'create']);
    Route::post('/api/payments/webhook', [WebhookController::class, 'handlePayment']);
// });
