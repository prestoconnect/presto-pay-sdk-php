<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CheckoutController::class, 'index']);
Route::post('/checkout', [CheckoutController::class, 'store']);

Route::get('/return/{txnRefNum?}', [ReturnController::class, 'show']);

Route::get('/payments/{paymentRefNum}', [PaymentController::class, 'query']);
Route::post('/payments/{paymentRefNum}/reverse', [PaymentController::class, 'reverse']);
Route::post('/payments/{paymentRefNum}/refund', [PaymentController::class, 'refund']);

Route::post('/presto/notify', [WebhookController::class, 'notify']);
