<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Booking, Deposit};
use App\Services\StripeDepositService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class DepositController extends Controller
{
    public function authorize(Request $request, StripeDepositService $stripe)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'payment_method_id' => 'required|string'
        ]);
        $booking = Booking::with('deposit')->findOrFail($data['booking_id']);

        $deposit = $booking->deposit ?: Deposit::create(['booking_id' => $booking->id]);

        try {
            $intent = $stripe->authorize($booking->deposit_amount_cents, $data['payment_method_id'], "BKG{$booking->id}");
            $deposit->update([
                'stripe_payment_intent_id' => $intent->id,
                'status' => $intent->status === 'requires_capture' ? 'authorized' : 'pending',
                'authorized_amount_cents' => $booking->deposit_amount_cents,
                'authorized_at' => now(),
                'last_error' => null,
            ]);
            return response()->json([
                'payment_intent_id' => $intent->id,
                'status' => $deposit->status,
                'client_secret' => $intent->client_secret ?? null
            ]);
        } catch (Throwable $e) {
            $deposit->update([
                'status' => 'failed',
                'last_error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'authorization_failed', 'message' => $e->getMessage()], 422);
        }
    }

    public function release(Request $request, StripeDepositService $stripe)
    {
        $data = $request->validate(['booking_id' => 'required|exists:bookings,id']);
        $deposit = Deposit::where('booking_id', $data['booking_id'])->firstOrFail();

        if ($deposit->status !== 'authorized') {
            throw ValidationException::withMessages(['deposit' => 'Release only allowed when status=authorized']);
        }

        try {
            $intent = $stripe->release($deposit->stripe_payment_intent_id);
            $deposit->update(['status' => 'released', 'released_at' => now()]);
            return response()->json(['status' => 'released', 'payment_intent' => $intent->id]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'release_failed', 'message' => $e->getMessage()], 422);
        }
    }

    public function capture(Request $request, StripeDepositService $stripe)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'amount_cents' => 'required|integer|min:1'
        ]);
        $deposit = Deposit::where('booking_id', $data['booking_id'])->firstOrFail();

        if ($deposit->status !== 'authorized') {
            throw ValidationException::withMessages(['deposit' => 'Capture only allowed when status=authorized']);
        }
        if ($data['amount_cents'] > $deposit->authorized_amount_cents) {
            throw ValidationException::withMessages(['amount_cents' => 'Capture amount exceeds authorized amount']);
        }

        try {
            $intent = $stripe->capture($deposit->stripe_payment_intent_id, $data['amount_cents']);
            $deposit->update([
                'status' => 'captured',
                'captured_amount_cents' => $data['amount_cents'],
                'captured_at' => now()
            ]);
            return response()->json(['status' => 'captured', 'captured_amount_cents' => $deposit->captured_amount_cents, 'payment_intent' => $intent->id]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'capture_failed', 'message' => $e->getMessage()], 422);
        }
    }
}
