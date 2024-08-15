<?php

use App\Http\Controllers\Api\FlashSaleController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\StrategyLabController;
use Illuminate\Support\Facades\Route;

Route::get('flash-sales', [FlashSaleController::class, 'index']);
Route::get('flash-sales/{sale}', [FlashSaleController::class, 'show']);

Route::middleware('shopper')->group(function () {
    Route::middleware('idempotent')->group(function () {
        Route::post('flash-sales/{sale}/reservations', [ReservationController::class, 'store'])
            ->whereNumber('sale');
        Route::post('flash-sales/{sale}/reservations/{token}/confirm', [ReservationController::class, 'confirm'])
            ->whereNumber('sale')->whereUuid('token');
    });

    Route::delete('flash-sales/{sale}/reservations/{token}', [ReservationController::class, 'destroy'])
        ->whereNumber('sale')->whereUuid('token');

    Route::post('lab/flash-sales/{sale}/purchase/{strategy}', StrategyLabController::class)
        ->whereNumber('sale');
});
