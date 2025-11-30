<?php

use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () { return view('home');});

Route::get('/api/products/{id}', [ProductController::class, 'show']);

Route::post('/api/holds', [HoldController::class, 'create']);

Route::post('/api/orders', [OrderController::class, 'create']);

Route::post('/api/payments/webhook', [WebhookController::class, 'handlePayment']);