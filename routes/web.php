<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\DemoController;
use App\Models\Booking;

// Route d'accueil
Route::get('/', function () {
    return redirect('/demo/bookings');
});

// Routes de démonstration
Route::get('/demo/bookings', [DemoController::class, 'bookings'])->name('demo.bookings');
Route::get('/demo/checkout/{booking}', [DemoController::class, 'checkout'])->name('demo.checkout');
Route::post('/demo/process-payment/{booking}', [DemoController::class, 'processPayment'])->name('demo.process-payment');

// Routes API
Route::prefix('api')->group(function () {
    Route::post('/deposits/authorize', [DepositController::class, 'authorizeDeposit']);
    Route::post('/deposits/release', [DepositController::class, 'release']);
    Route::post('/deposits/capture', [DepositController::class, 'capture']);
});
// Ajoutez cette route
Route::get('/booking/confirmation/{booking}', function (Booking $booking) {
    return view('booking.confirmation', compact('booking'));
})->name('booking.confirmation');
// Webhook Stripe (doit être en POST)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);
