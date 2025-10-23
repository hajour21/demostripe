<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('demo.bookings');
});

// Routes démo
Route::get('/demo/bookings', [DemoController::class, 'bookings'])->name('demo.bookings');
Route::get('/demo/checkout/{booking}', [DemoController::class, 'checkout'])->name('demo.checkout');
Route::post('/demo/checkout/{booking}', [DemoController::class, 'processCheckout'])->name('demo.process-checkout');
Route::post('/demo/release/{booking}', [DemoController::class, 'releaseDeposit'])->name('demo.release');
Route::post('/demo/capture/{booking}', [DemoController::class, 'captureDeposit'])->name('demo.capture');

// Route de test
Route::get('/test', function () {
    return response()->json(['message' => 'Laravel is working!']);
});