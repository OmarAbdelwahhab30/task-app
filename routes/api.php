<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::resources([
    'products' => ProductController::class,
]);


Route::post('/holds', [ProductController::class, 'createHold']);
Route::post('/orders', [ProductController::class, 'createOrder']);
Route::post('payments/webhook', [ProductController::class, 'handleWebhook']);
