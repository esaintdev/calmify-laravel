<?php

use Calmify\Laravel\Http\ReceiverController;
use Illuminate\Support\Facades\Route;

// GET answers Calmify's connection check; POST receives a post (or an
// update of one); DELETE unpublishes one.
Route::middleware(['api', 'throttle:30,1'])->group(function () {
    Route::get(config('calmify.route'), [ReceiverController::class, 'ping']);
    Route::post(config('calmify.route'), [ReceiverController::class, 'receive']);
    Route::delete(config('calmify.route'), [ReceiverController::class, 'unpublish']);
});
