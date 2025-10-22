<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;
use Illuminate\Support\Facades\Log;

class StripeDepositService
{
    private string $currency;

    public function __construct()
    {
        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \Exception('Stripe secret key not configured');
        }

        Stripe::setApiKey($stripeSecret);
        Stripe::setAppInfo(config('app.name'), '1.0.0');
        
        $this->currency = config('services.stripe.currency', 'eur');
    }

    public function authorize(int $amountCents, string $paymentMethodId, string $bookingRef): array
    {
        try {
            Log::info('StripeDepositService: Creating authorization', [
                'amount' => $amountCents,
                'booking_ref' => $bookingRef
            ]);

            $intent = PaymentIntent::create([
                'amount' => $amountCents,
                'currency' => $this->currency,
                'payment_method' => $paymentMethodId,
                'confirm' => true,
                'capture_method' => 'manual',
                'description' => "Deposit for booking {$bookingRef}",
                'metadata' => [
                    'booking_id' => $bookingRef,
                    'type' => 'deposit_authorization'
                ],
                'payment_method_types' => ['card'],
                // Optionnel : autoriser d'autres méthodes de paiement
                // 'automatic_payment_methods' => ['enabled' => true],
            ], [
                'idempotency_key' => $this->generateIdempotencyKey('auth', $bookingRef, $amountCents)
            ]);

            Log::info('StripeDepositService: PaymentIntent created', [
                'id' => $intent->id,
                'status' => $intent->status,
                'next_action' => $intent->next_action ?? null
            ]);

            return [
                'id' => $intent->id,
                'status' => $intent->status,
                'client_secret' => $intent->client_secret,
                'requires_action' => $intent->status === 'requires_action'
            ];

        } catch (ApiErrorException $e) {
            $this->handleStripeError($e, 'authorization', $bookingRef);
            throw $e;
        }
    }

    public function capture(string $paymentIntentId, int $amountCents = null): PaymentIntent
    {
        try {
            Log::info('StripeDepositService: Capturing payment', [
                'payment_intent_id' => $paymentIntentId,
                'amount_cents' => $amountCents
            ]);

            $captureParams = [];
            if ($amountCents !== null) {
                $captureParams['amount_to_capture'] = $amountCents;
            }

            $intent = PaymentIntent::capture($paymentIntentId, $captureParams);

            Log::info('StripeDepositService: Payment captured', [
                'id' => $intent->id,
                'amount_captured' => $intent->amount_captured
            ]);

            return $intent;

        } catch (ApiErrorException $e) {
            $this->handleStripeError($e, 'capture', $paymentIntentId);
            throw $e;
        }
    }

    public function release(string $paymentIntentId): PaymentIntent
    {
        try {
            Log::info('StripeDepositService: Releasing authorization', [
                'payment_intent_id' => $paymentIntentId
            ]);

            $intent = PaymentIntent::cancel($paymentIntentId);

            Log::info('StripeDepositService: Authorization released', [
                'id' => $intent->id,
                'status' => $intent->status
            ]);

            return $intent;

        } catch (ApiErrorException $e) {
            $this->handleStripeError($e, 'release', $paymentIntentId);
            throw $e;
        }
    }

    public function retrieve(string $paymentIntentId): PaymentIntent
    {
        try {
            return PaymentIntent::retrieve($paymentIntentId);
        } catch (ApiErrorException $e) {
            $this->handleStripeError($e, 'retrieve', $paymentIntentId);
            throw $e;
        }
    }

    private function handleStripeError(ApiErrorException $e, string $operation, string $context): void
    {
        Log::error("StripeDepositService: {$operation} failed", [
            'error' => $e->getMessage(),
            'stripe_error_type' => $e->getError()->type ?? 'unknown',
            'stripe_code' => $e->getError()->code ?? 'unknown',
            'context' => $context,
            'operation' => $operation
        ]);
    }

    private function generateIdempotencyKey(string $operation, string $bookingRef, int $amountCents): string
    {
        return "dep_{$operation}_{$bookingRef}_{$amountCents}_" . substr(md5(time()), 0, 8);
    }
}