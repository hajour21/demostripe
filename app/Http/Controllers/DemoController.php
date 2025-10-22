<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    public function bookings()
    {
        $bookings = Booking::with('deposit')->orderBy('id')->get();
        return view('demo.bookings', compact('bookings'));
    }

    public function checkout(Booking $booking)
    {
        return view('demo.checkout', compact('booking'));
    }

    public function processPayment(Request $request, Booking $booking)
    {
        // Logique de traitement du paiement
        return redirect()->route('demo.bookings')->with('success', 'Paiement traité');
    }
}
