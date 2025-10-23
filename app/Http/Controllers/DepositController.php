<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Deposit;
use App\Services\StripeDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DepositController extends Controller
{
    private bool $testMode;

    public function __construct()
    {
        $this->testMode = config('app.env') === 'testing' || config('services.stripe.test_mode', false);
    }

    public function authorizeDeposit(Request $request, StripeDepositService $stripeService): JsonResponse
    {
        Log::info('DepositController: authorizeDeposit started', [
            'booking_id' => $request->booking_id,
            'test_mode' => $this->testMode
        ]);

        try {
            $validated = $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'payment_method_id' => 'required|string',
                'metadata' => 'sometimes|array',
            ]);

            return DB::transaction(function () use ($validated, $stripeService) {
                $booking = Booking::findOrFail($validated['booking_id']);

                // Vérification existence dépôt
                if ($booking->deposit) {
                    Log::warning('DepositController: Deposit already exists', [
                        'booking_id' => $booking->id,
                        'existing_deposit_id' => $booking->deposit->id
                    ]);

                    return response()->json([
                        'success' => false,
                        'error' => 'Deposit already exists for this booking',
                        'deposit_id' => $booking->deposit->id
                    ], 422);
                }

                // Vérification du montant
                if ($booking->deposit_amount_cents <= 0) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Invalid deposit amount'
                    ], 422);
                }

                // Autorisation Stripe ou simulation
                if ($this->testMode) {
                    Log::info('DepositController: Using test mode for authorization');

                    $paymentIntentId = 'pi_test_' . uniqid();
                    $status = 'requires_capture';
                    $clientSecret = 'test_client_secret_' . uniqid();
                    $requiresAction = false;
                } else {
                    $result = $stripeService->authorize(
                        $booking->deposit_amount_cents,
                        $validated['payment_method_id'],
                        "booking_{$booking->id}",
                        $validated['metadata'] ?? []
                    );

                    $paymentIntentId = $result['id'];
                    $status = $result['status'];
                    $clientSecret = $result['client_secret'];
                    $requiresAction = $result['requires_action'] ?? false;
                }

                // Création du dépôt
                $deposit = Deposit::create([
                    'booking_id' => $booking->id,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'status' => $this->mapStripeStatus($status),
                    'authorized_amount_cents' => $booking->deposit_amount_cents,
                    'authorized_at' => $status === 'requires_capture' ? now() : null,
                    'test_mode' => $this->testMode,
                ]);

                Log::info('DepositController: Deposit created successfully', [
                    'deposit_id' => $deposit->id,
                    'payment_intent_id' => $paymentIntentId,
                    'status' => $status,
                    'test_mode' => $this->testMode
                ]);

                // Construction de la réponse
                $response = [
                    'success' => true,
                    'deposit' => $deposit->load('booking'),
                    'payment_intent' => [
                        'id' => $paymentIntentId,
                        'status' => $status,
                        'client_secret' => $requiresAction ? $clientSecret : null,
                        'requires_action' => $requiresAction,
                    ]
                ];

                if ($this->testMode) {
                    $response['test_mode'] = true;
                    $response['message'] = 'TEST MODE - No real payment processed';
                }

                return response()->json($response);
            });
        } catch (ValidationException $e) {
            Log::warning('DepositController: Validation failed', [
                'errors' => $e->errors(),
                'booking_id' => $request->booking_id
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'messages' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('DepositController: Authorization failed', [
                'error' => $e->getMessage(),
                'booking_id' => $request->booking_id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Authorization failed',
                'message' => $this->testMode ? $e->getMessage() : 'Unable to process payment authorization',
                'test_mode' => $this->testMode
            ], 422);
        }
    }

    public function release(Request $request, StripeDepositService $stripeService): JsonResponse
    {
        Log::info('DepositController: release started', [
            'booking_id' => $request->booking_id,
            'test_mode' => $this->testMode
        ]);

        try {
            $validated = $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'reason' => 'sometimes|string|max:255',
            ]);

            $booking = Booking::findOrFail($validated['booking_id']);
            $deposit = $booking->deposit;

            if (!$deposit) {
                return response()->json([
                    'success' => false,
                    'error' => 'No deposit found for this booking'
                ], 404);
            }

            if (!$deposit->canBeReleased()) {
                Log::warning('DepositController: Deposit cannot be released', [
                    'deposit_id' => $deposit->id,
                    'current_status' => $deposit->status,
                    'captured_amount' => $deposit->captured_amount_cents
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Deposit cannot be released in its current state',
                    'current_status' => $deposit->status,
                    'captured_amount' => $deposit->captured_amount
                ], 422);
            }

            // Libération Stripe ou simulation
            if (!$this->testMode) {
                $stripeService->release($deposit->stripe_payment_intent_id);
            }

            $deposit->update([
                'status' => Deposit::STATUS_RELEASED,
                'released_at' => now(),
                'release_reason' => $validated['reason'] ?? null,
            ]);

            Log::info('DepositController: Deposit released successfully', [
                'deposit_id' => $deposit->id,
                'test_mode' => $this->testMode
            ]);

            $response = [
                'success' => true,
                'message' => 'Deposit released successfully',
                'deposit' => $deposit->fresh()
            ];

            if ($this->testMode) {
                $response['test_mode'] = true;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('DepositController: Release failed', [
                'error' => $e->getMessage(),
                'booking_id' => $request->booking_id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Release failed',
                'message' => $this->testMode ? $e->getMessage() : 'Unable to release deposit',
                'test_mode' => $this->testMode
            ], 422);
        }
    }

    public function capture(Request $request, StripeDepositService $stripeService): JsonResponse
    {
        Log::info('DepositController: capture started', [
            'booking_id' => $request->booking_id,
            'amount_cents' => $request->amount_cents,
            'test_mode' => $this->testMode
        ]);

        try {
            $validated = $request->validate([
                'booking_id' => 'required|exists:bookings,id',
                'amount_cents' => 'required|integer|min:1',
                'reason' => 'sometimes|string|max:255',
            ]);

            $booking = Booking::findOrFail($validated['booking_id']);
            $deposit = $booking->deposit;

            if (!$deposit) {
                return response()->json([
                    'success' => false,
                    'error' => 'No deposit found for this booking'
                ], 404);
            }

            if (!$deposit->canBeCaptured($validated['amount_cents'])) {
                Log::warning('DepositController: Deposit cannot be captured', [
                    'deposit_id' => $deposit->id,
                    'requested_amount' => $validated['amount_cents'],
                    'authorized_amount' => $deposit->authorized_amount_cents,
                    'current_status' => $deposit->status
                ]);

                throw ValidationException::withMessages([
                    'amount_cents' => [
                        'Cannot capture this amount. ' .
                            'Authorized: ' . $deposit->authorized_amount_cents . ' cents, ' .
                            'Already captured: ' . $deposit->captured_amount_cents . ' cents, ' .
                            'Status: ' . $deposit->status
                    ]
                ]);
            }

            // Capture Stripe ou simulation
            if (!$this->testMode) {
                $stripeService->capture($deposit->stripe_payment_intent_id, $validated['amount_cents']);
            }

            $newCapturedAmount = $deposit->captured_amount_cents + $validated['amount_cents'];
            $isFullyCaptured = $newCapturedAmount >= $deposit->authorized_amount_cents;

            $deposit->update([
                'status' => $isFullyCaptured ? Deposit::STATUS_CAPTURED : Deposit::STATUS_AUTHORIZED,
                'captured_amount_cents' => $newCapturedAmount,
                'captured_at' => now(),
                'capture_reason' => $validated['reason'] ?? null,
            ]);

            Log::info('DepositController: Deposit captured successfully', [
                'deposit_id' => $deposit->id,
                'amount_cents' => $validated['amount_cents'],
                'new_captured_amount' => $newCapturedAmount,
                'is_fully_captured' => $isFullyCaptured,
                'test_mode' => $this->testMode
            ]);

            $response = [
                'success' => true,
                'message' => 'Deposit captured successfully',
                'amount_captured' => $validated['amount_cents'],
                'total_captured' => $newCapturedAmount,
                'is_fully_captured' => $isFullyCaptured,
                'deposit' => $deposit->fresh()
            ];

            if ($this->testMode) {
                $response['test_mode'] = true;
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            Log::warning('DepositController: Capture validation failed', [
                'errors' => $e->errors(),
                'booking_id' => $request->booking_id
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'messages' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('DepositController: Capture failed', [
                'error' => $e->getMessage(),
                'booking_id' => $request->booking_id,
                'amount_cents' => $request->amount_cents,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Capture failed',
                'message' => $this->testMode ? $e->getMessage() : 'Unable to capture deposit',
                'test_mode' => $this->testMode
            ], 422);
        }
    }

    private function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_capture' => Deposit::STATUS_AUTHORIZED,
            'succeeded', 'processing' => Deposit::STATUS_CAPTURED,
            'canceled' => Deposit::STATUS_RELEASED,
            'requires_action', 'requires_payment_method' => Deposit::STATUS_PENDING,
            default => Deposit::STATUS_PENDING,
        };
    }

    // Nouvelle méthode pour récupérer le statut d'un dépôt
    public function getDepositStatus(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'booking_id' => 'required|exists:bookings,id',
            ]);

            $booking = Booking::findOrFail($validated['booking_id']);

            if (!$booking->deposit) {
                return response()->json([
                    'success' => false,
                    'error' => 'No deposit found for this booking'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'deposit' => $booking->deposit->load('booking'),
                'status' => $booking->deposit->status,
                'amounts' => [
                    'authorized' => $booking->deposit->authorized_amount,
                    'captured' => $booking->deposit->captured_amount,
                    'remaining' => $booking->deposit->remaining_amount,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('DepositController: Get status failed', [
                'error' => $e->getMessage(),
                'booking_id' => $request->booking_id
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Unable to retrieve deposit status'
            ], 500);
        }
    }
}
