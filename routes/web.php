<?php

use App\Http\Controllers\SimulatorController;
use Illuminate\Support\Facades\Route;

Route::get('/', SimulatorController::class)->name('simulator');
