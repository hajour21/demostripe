<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Deposit;
use App\Services\StripeDepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemoController extends Controller
{
    public function bookings()
    {
        $bookings = Booking::with('deposit')->get();

        // Debug: vérifier les données
        Log::info('Bookings loaded: ' . $bookings->count());
        foreach ($bookings as $booking) {
            Log::info("Booking {$booking->id}: " . ($booking->deposit ? 'has deposit - ' . $booking->deposit->status : 'no deposit'));
        }

        return view('demo.bookings', compact('bookings'));
    }

    public function checkout(Booking $booking)
    {
        if ($booking->deposit) {
            return redirect()->route('demo.bookings')
                ->with('error', 'Un dépôt existe déjà pour cette réservation.');
        }

        $stripeKey = config('services.stripe.key');

        return view('demo.checkout', compact('booking', 'stripeKey'));
    }

    public function processCheckout(Request $request, Booking $booking)
{
    \Log::info('DemoController: processCheckout called', [
        'booking_id' => $booking->id,
        'has_payment_method' => !empty($request->payment_method_id)
    ]);

    // S'assurer que la réponse est toujours en JSON
    try {
        // Valider que c'est une requête JSON
        if (!$request->wantsJson() && !$request->isJson()) {
            \Log::warning('DemoController: Request is not JSON', [
                'content_type' => $request->header('Content-Type'),
                'accept' => $request->header('Accept')
            ]);
        }

        $validatedData = $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        $stripeService = app(StripeDepositService::class);
        
        // Vérifier si un dépôt existe déjà
        if ($booking->deposit) {
            \Log::warning('DemoController: Deposit already exists', [
                'booking_id' => $booking->id,
                'deposit_id' => $booking->deposit->id
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Un dépôt existe déjà pour cette réservation.',
                'deposit_id' => $booking->deposit->id
            ], 422);
        }

        // Vérifier le montant de la caution
        if ($booking->deposit_amount_cents <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'Le montant de la caution est invalide.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            \Log::info('DemoController: Creating Stripe authorization', [
                'amount_cents' => $booking->deposit_amount_cents,
                'booking_ref' => "booking_{$booking->id}"
            ]);

            // Créer l'autorisation Stripe
            $result = $stripeService->authorize(
                $booking->deposit_amount_cents,
                $validatedData['payment_method_id'],
                "booking_{$booking->id}",
                ['booking_id' => (string) $booking->id]
            );

            \Log::info('DemoController: Stripe authorization result', [
                'payment_intent_id' => $result['id'],
                'status' => $result['status'],
                'requires_action' => $result['requires_action'],
                'requires_capture' => $result['requires_capture']
            ]);

            // Déterminer le statut basé sur la réponse Stripe
            $status = 'pending';
            $authorizedAt = null;

            if ($result['status'] === 'requires_capture') {
                $status = 'authorized';
                $authorizedAt = now();
            } elseif ($result['status'] === 'succeeded') {
                $status = 'captured';
                $authorizedAt = now();
            }

            // Créer le dépôt en base
            $deposit = Deposit::create([
                'booking_id' => $booking->id,
                'stripe_payment_intent_id' => $result['id'],
                'status' => $status,
                'authorized_amount_cents' => $booking->deposit_amount_cents,
                'authorized_at' => $authorizedAt,
            ]);

            DB::commit();

            \Log::info('DemoController: Deposit created successfully', [
                'deposit_id' => $deposit->id,
                'status' => $deposit->status,
                'stripe_payment_intent_id' => $deposit->stripe_payment_intent_id
            ]);

            // Préparer la réponse de succès
            $response = [
                'success' => true,
                'message' => 'Autorisation de caution créée avec succès!',
                'deposit' => [
                    'id' => $deposit->id,
                    'status' => $deposit->status,
                    'authorized_amount' => $deposit->authorized_amount,
                ],
                'payment_intent' => [
                    'id' => $result['id'],
                    'status' => $result['status'],
                    'requires_action' => $result['requires_action'],
                    'requires_capture' => $result['requires_capture'],
                ]
            ];

            // Si une action 3DS est requise, inclure le client_secret
            if ($result['requires_action'] && !empty($result['client_secret'])) {
                $response['payment_intent']['client_secret'] = $result['client_secret'];
                $response['message'] = 'Authentification 3DS requise. Veuillez confirmer le paiement.';
                $response['requires_3ds'] = true;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('DemoController: Checkout transaction failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Erreur lors de la création de l\'autorisation: ' . $e->getMessage()
            ], 422);
        }

    } catch (ValidationException $e) {
        \Log::warning('DemoController: Checkout validation failed', [
            'booking_id' => $booking->id,
            'errors' => $e->errors()
        ]);
        
        return response()->json([
            'success' => false,
            'error' => 'Données invalides',
            'messages' => $e->errors()
        ], 422);
        
    } catch (\Exception $e) {
        \Log::error('DemoController: Checkout process failed unexpectedly', [
            'booking_id' => $booking->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'error' => 'Une erreur inattendue s\'est produite. Veuillez réessayer.'
        ], 500);
    }
}

    public function releaseDeposit(Booking $booking)
    {
        Log::info('DemoController: releaseDeposit called', ['booking_id' => $booking->id]);

        try {
            $stripeService = app(StripeDepositService::class);

            // Vérifier si le dépôt existe
            if (!$booking->deposit) {
                Log::warning('DemoController: No deposit found', ['booking_id' => $booking->id]);
                return back()->with('error', 'Aucun dépôt trouvé pour cette réservation.');
            }

            Log::info('DemoController: Deposit found', [
                'deposit_id' => $booking->deposit->id,
                'status' => $booking->deposit->status,
                'captured_amount' => $booking->deposit->captured_amount_cents
            ]);

            // Vérifier si on peut relâcher
            if (!$booking->deposit->canBeReleased()) {
                Log::warning('DemoController: Deposit cannot be released', [
                    'deposit_id' => $booking->deposit->id,
                    'status' => $booking->deposit->status,
                    'captured_amount' => $booking->deposit->captured_amount_cents
                ]);
                return back()->with('error', 'Impossible de relâcher ce dépôt. Statut: ' . $booking->deposit->status);
            }

            DB::beginTransaction();

            try {
                // Relâcher via le service amélioré
                Log::info('DemoController: Calling Stripe release', [
                    'payment_intent_id' => $booking->deposit->stripe_payment_intent_id
                ]);

                $stripeService->release($booking->deposit->stripe_payment_intent_id);

                $booking->deposit->update([
                    'status' => 'released',
                    'released_at' => now(),
                ]);

                DB::commit();

                Log::info('DemoController: Deposit released successfully', ['booking_id' => $booking->id]);
                return back()->with('success', 'Caution relâchée avec succès!');
            } catch (\Exception $e) {
                DB::rollBack();

                $booking->deposit->update(['last_error' => $e->getMessage()]);
                Log::error('DemoController: Release failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return back()->with('error', 'Erreur lors du relâchement: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('DemoController: Release process failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);
            return back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }

    public function captureDeposit(Request $request, Booking $booking)
    {
        Log::info('DemoController: captureDeposit called', [
            'booking_id' => $booking->id,
            'amount' => $request->amount
        ]);

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $stripeService = app(StripeDepositService::class);
            $amountCents = (int)($request->amount * 100);

            Log::info('DemoController: Capture parameters', [
                'amount_euros' => $request->amount,
                'amount_cents' => $amountCents
            ]);

            // Vérifier si le dépôt existe
            if (!$booking->deposit) {
                Log::warning('DemoController: No deposit found for capture', ['booking_id' => $booking->id]);
                return back()->with('error', 'Aucun dépôt trouvé pour cette réservation.');
            }

            Log::info('DemoController: Deposit details', [
                'deposit_id' => $booking->deposit->id,
                'status' => $booking->deposit->status,
                'authorized_amount' => $booking->deposit->authorized_amount_cents,
                'captured_amount' => $booking->deposit->captured_amount_cents,
                'remaining_amount' => $booking->deposit->getRemainingAmountAttribute()
            ]);

            // Vérifier si on peut capturer
            if (!$booking->deposit->canBeCaptured($amountCents)) {
                $maxAmount = $booking->deposit->getRemainingAmountAttribute();
                Log::warning('DemoController: Cannot capture amount', [
                    'requested' => $request->amount,
                    'available' => $maxAmount,
                    'authorized' => $booking->deposit->authorized_amount_cents,
                    'already_captured' => $booking->deposit->captured_amount_cents
                ]);
                return back()->with('error', "Impossible de capturer {$request->amount}€. Montant disponible: {$maxAmount}€");
            }

            DB::beginTransaction();

            try {
                // Capturer le montant
                Log::info('DemoController: Calling Stripe capture', [
                    'payment_intent_id' => $booking->deposit->stripe_payment_intent_id,
                    'amount_cents' => $amountCents
                ]);

                $stripeService->capture($booking->deposit->stripe_payment_intent_id, $amountCents);

                // Calculer le nouveau total capturé
                $newCapturedAmount = $booking->deposit->captured_amount_cents + $amountCents;

                // Déterminer le nouveau statut
                $newStatus = $newCapturedAmount >= $booking->deposit->authorized_amount_cents ? 'captured' : 'authorized';

                // Mettre à jour en base
                $booking->deposit->update([
                    'status' => $newStatus,
                    'captured_amount_cents' => $newCapturedAmount,
                    'captured_at' => now(),
                ]);

                DB::commit();

                Log::info('DemoController: Capture successful', [
                    'booking_id' => $booking->id,
                    'amount' => $request->amount,
                    'new_status' => $newStatus,
                    'total_captured' => $newCapturedAmount
                ]);

                $message = $newStatus === 'captured'
                    ? "Caution entièrement capturée! Montant: {$request->amount}€"
                    : "Capture partielle réussie! Montant: {$request->amount}€";

                return back()->with('success', $message);
            } catch (\Exception $e) {
                DB::rollBack();

                $booking->deposit->update(['last_error' => $e->getMessage()]);
                Log::error('DemoController: Capture failed', [
                    'booking_id' => $booking->id,
                    'amount' => $request->amount,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                return back()->with('error', 'Erreur lors de la capture: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('DemoController: Capture process failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage()
            ]);
            return back()->with('error', 'Erreur: ' . $e->getMessage());
        }
    }
}
