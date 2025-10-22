<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DepositController extends Controller
{
    public function authorizeDeposit(Request $request): JsonResponse
    {
        Log::info('=== DÉBUT authorizeDeposit (TEST MODE) ===', $request->all());

        try {
            $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'payment_method_id' => 'required|string',
            ]);

            $booking = Booking::findOrFail($request->booking_id);
            Log::info('Booking trouvé', ['booking_id' => $booking->id]);

            // MODE TEST - Pas d'appel à Stripe
            $paymentIntentId = 'pi_test_' . time() . '_' . rand(1000, 9999);
            $status = 'requires_capture';

            Log::info('Création du dépôt (TEST MODE)', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $status
            ]);

            $deposit = Deposit::create([
                'booking_id' => $booking->id,
                'stripe_payment_intent_id' => $paymentIntentId,
                'status' => 'authorized', // Directement authorized en mode test
                'authorized_amount_cents' => $booking->deposit_amount_cents,
                'authorized_at' => now(),
            ]);

            Log::info('=== SUCCÈS authorizeDeposit (TEST MODE) ===');

            return response()->json([
                'success' => true,
                'payment_intent_id' => $paymentIntentId,
                'status' => $status,
                'deposit_id' => $deposit->id,
                'message' => 'MODE TEST - Stripe désactivé'
            ]);
        } catch (\Exception $e) {
            Log::error('=== ERREUR authorizeDeposit ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function release(Request $request): JsonResponse
    {
        Log::info('=== DÉBUT release (TEST MODE) ===', $request->all());

        try {
            $request->validate([
                'booking_id' => 'required|exists:bookings,id',
            ]);

            $booking = Booking::findOrFail($request->booking_id);
            $deposit = $booking->deposit;

            if (!$deposit) {
                return response()->json(['error' => 'No deposit found'], 404);
            }

            $deposit->update([
                'status' => 'released',
                'released_at' => now()
            ]);

            Log::info('=== SUCCÈS release (TEST MODE) ===');

            return response()->json(['success' => true, 'message' => 'MODE TEST']);
        } catch (\Exception $e) {
            Log::error('=== ERREUR release ===', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function capture(Request $request): JsonResponse
    {
        Log::info('=== DÉBUT capture (TEST MODE) ===', $request->all());

        try {
            $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'amount_cents' => 'required|integer|min:1',
            ]);

            $booking = Booking::findOrFail($request->booking_id);
            $deposit = $booking->deposit;

            if (!$deposit) {
                return response()->json(['error' => 'No deposit found'], 404);
            }

            $deposit->update([
                'status' => 'captured',
                'captured_amount_cents' => $request->amount_cents,
                'captured_at' => now()
            ]);

            Log::info('=== SUCCÈS capture (TEST MODE) ===');

            return response()->json(['success' => true, 'message' => 'MODE TEST']);
        } catch (\Exception $e) {
            Log::error('=== ERREUR capture ===', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
