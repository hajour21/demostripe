<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripeDepositService
{
    private string $currency;

    public function __construct()
    {
        $secret = config('services.stripe.secret');
        if (empty($secret)) {
            throw new \RuntimeException('Stripe secret key not configured');
        }

        Stripe::setApiKey($secret);

        if (config('app.name')) {
            Stripe::setAppInfo(
                config('app.name'),
                (string) config('app.version', '1.0.0'),
                'https://github.com/your-repo'
            );
        }

        $this->currency = (string) config('services.stripe.currency', 'eur');
    }

    /**
     * Autorise (empreinte) un montant sur la carte
     */
    public function authorize(int $amountCents, string $paymentMethodId, string $bookingRef, array $metadata = []): array
    {
        try {
            Log::info('StripeDepositService: authorize()', [
                'amount' => $amountCents,
                'booking_ref' => $bookingRef,
                'currency' => $this->currency,
            ]);

            // CORRECTION : Clé unique à chaque tentative
            $idempotencyKey = $this->idemKey('auth', $bookingRef, $amountCents, true);

            $intent = PaymentIntent::create(
                [
                    'amount'               => $amountCents,
                    'currency'             => $this->currency,
                    'payment_method'       => $paymentMethodId,
                    'confirm'              => true,
                    'capture_method'       => 'manual',
                    'payment_method_types' => ['card'],
                    'description'          => "Deposit for booking {$bookingRef}",
                    'metadata'             => array_merge([
                        'booking_ref' => $bookingRef,
                        'type'        => 'deposit_authorization',
                    ], $metadata),
                    'use_stripe_sdk'       => true,
                    'confirmation_method'  => 'manual',
                ],
                [
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            Log::info('StripeDepositService: authorization response', [
                'id'                => $intent->id,
                'status'            => $intent->status,
                'amount'            => $intent->amount,
                'amount_capturable' => $intent->amount_capturable ?? 0,
                'client_secret'     => $intent->client_secret ? 'present' : 'absent',
                'next_action_type'  => $intent->next_action->type ?? null,
            ]);

            return [
                'id'                 => $intent->id,
                'status'             => $intent->status,
                'client_secret'      => $intent->client_secret,
                'requires_action'    => $intent->status === 'requires_action',
                'requires_capture'   => $intent->status === 'requires_capture',
                'next_action'        => $intent->next_action,
                'amount'             => $intent->amount,
                'currency'           => $intent->currency,
                'amount_capturable'  => (int) ($intent->amount_capturable ?? 0),
            ];
        } catch (ApiErrorException $e) {
            $this->logStripeError($e, 'authorization', $bookingRef);
            throw new \RuntimeException("Payment authorization failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Capture (total ou partiel)
     */
    public function capture(string $paymentIntentId, ?int $amountCents = null): PaymentIntent
    {
        try {
            $current = $this->retrieve($paymentIntentId);

            if ($current->status !== 'requires_capture') {
                Log::warning('StripeDepositService: capture skipped - invalid status', [
                    'id' => $paymentIntentId,
                    'status' => $current->status,
                    'amount_capturable' => $current->amount_capturable ?? 0,
                ]);
                throw new \InvalidArgumentException("Cannot capture payment intent with status: {$current->status}");
            }

            $maxCapturable = (int) ($current->amount_capturable ?? 0);
            $toCapture = $amountCents ?? $maxCapturable;

            if ($toCapture <= 0) {
                throw new \InvalidArgumentException('Capture amount must be positive');
            }

            if ($toCapture > $maxCapturable) {
                throw new \InvalidArgumentException(
                    "Capture amount ({$toCapture}) exceeds capturable amount ({$maxCapturable})"
                );
            }

            Log::info('StripeDepositService: capture()', [
                'payment_intent_id' => $paymentIntentId,
                'amount_to_capture' => $toCapture,
                'max_capturable' => $maxCapturable,
            ]);

            // CORRECTION : Clé unique pour capture
            $idempotencyKey = $this->idemKey('capture', $paymentIntentId, $toCapture, true);

            $intent = $current->capture([
                'amount_to_capture' => $toCapture,
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            Log::info('StripeDepositService: capture successful', [
                'id'               => $intent->id,
                'status'           => $intent->status,
                'amount_captured'  => $intent->amount_captured,
                'amount_capturable' => $intent->amount_capturable ?? 0,
            ]);

            return $intent;
        } catch (ApiErrorException $e) {
            $this->logStripeError($e, 'capture', $paymentIntentId);
            throw new \RuntimeException("Payment capture failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Relâcher l'autorisation
     */
    public function release(string $paymentIntentId): PaymentIntent
    {
        try {
            $current = $this->retrieve($paymentIntentId);

            if (in_array($current->status, ['canceled', 'succeeded'])) {
                Log::info('StripeDepositService: release noop - already in final state', [
                    'id'     => $paymentIntentId,
                    'status' => $current->status,
                ]);
                return $current;
            }

            if (!in_array($current->status, ['requires_capture', 'requires_action', 'requires_payment_method'])) {
                Log::warning('StripeDepositService: release skipped - invalid state', [
                    'id'     => $paymentIntentId,
                    'status' => $current->status,
                ]);
                throw new \InvalidArgumentException("Cannot release payment intent with status: {$current->status}");
            }

            Log::info('StripeDepositService: release()', [
                'payment_intent_id' => $paymentIntentId,
                'current_status' => $current->status,
                'amount_capturable' => $current->amount_capturable ?? 0,
            ]);

            // CORRECTION : Clé unique pour release
            $idempotencyKey = $this->idemKey('release', $paymentIntentId, (int) ($current->amount_capturable ?? 0), true);

            $intent = $current->cancel([], [
                'idempotency_key' => $idempotencyKey,
            ]);

            Log::info('StripeDepositService: release successful', [
                'id'     => $intent->id,
                'status' => $intent->status,
            ]);

            return $intent;
        } catch (ApiErrorException $e) {
            $this->logStripeError($e, 'release', $paymentIntentId);
            throw new \RuntimeException("Authorization release failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Récupère un PaymentIntent
     */
    public function retrieve(string $paymentIntentId): PaymentIntent
    {
        try {
            $intent = PaymentIntent::retrieve($paymentIntentId);

            Log::debug('StripeDepositService: retrieve', [
                'id' => $intent->id,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'amount_capturable' => $intent->amount_capturable ?? 0,
            ]);

            return $intent;
        } catch (ApiErrorException $e) {
            $this->logStripeError($e, 'retrieve', $paymentIntentId);
            throw new \RuntimeException("Payment intent retrieval failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Confirme un PaymentIntent (pour 3DS)
     */
    public function confirm(string $paymentIntentId, string $paymentMethodId = null): PaymentIntent
    {
        try {
            $current = $this->retrieve($paymentIntentId);

            $params = [];
            if ($paymentMethodId) {
                $params['payment_method'] = $paymentMethodId;
            }

            Log::info('StripeDepositService: confirm()', [
                'payment_intent_id' => $paymentIntentId,
                'has_payment_method' => !empty($paymentMethodId),
            ]);

            // CORRECTION : Clé unique pour confirm
            $idempotencyKey = $this->idemKey('confirm', $paymentIntentId, time(), true);

            $intent = $current->confirm($params, [
                'idempotency_key' => $idempotencyKey,
            ]);

            Log::info('StripeDepositService: confirm result', [
                'id' => $intent->id,
                'status' => $intent->status,
                'requires_action' => $intent->status === 'requires_action',
            ]);

            return $intent;
        } catch (ApiErrorException $e) {
            $this->logStripeError($e, 'confirm', $paymentIntentId);
            throw new \RuntimeException("Payment confirmation failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Vérifie si on peut capturer un montant
     */
    public function canCapture(string $paymentIntentId, int $amountCents): bool
    {
        try {
            $pi = $this->retrieve($paymentIntentId);

            if ($pi->status !== 'requires_capture') {
                Log::debug('StripeDepositService: cannot capture - wrong status', [
                    'status' => $pi->status,
                    'required_status' => 'requires_capture',
                ]);
                return false;
            }

            $capturable = (int) ($pi->amount_capturable ?? 0);

            if ($capturable <= 0) {
                Log::debug('StripeDepositService: cannot capture - no capturable amount', [
                    'capturable' => $capturable,
                ]);
                return false;
            }

            $canCapture = $amountCents > 0 && $amountCents <= $capturable;

            Log::debug('StripeDepositService: canCapture check', [
                'amount_cents' => $amountCents,
                'capturable' => $capturable,
                'result' => $canCapture,
            ]);

            return $canCapture;
        } catch (\Throwable $e) {
            Log::warning('StripeDepositService: canCapture error', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Vérifie si un PaymentIntent nécessite une action (3DS)
     */
    public function requiresAction(string $paymentIntentId): bool
    {
        try {
            $pi = $this->retrieve($paymentIntentId);
            return $pi->status === 'requires_action';
        } catch (\Throwable $e) {
            Log::warning('StripeDepositService: requiresAction error', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Récupère le client_secret pour le frontend
     */
    public function getClientSecret(string $paymentIntentId): ?string
    {
        try {
            $pi = $this->retrieve($paymentIntentId);
            return $pi->client_secret;
        } catch (\Throwable $e) {
            Log::warning('StripeDepositService: getClientSecret error', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log des erreurs Stripe détaillé
     */
    private function logStripeError(ApiErrorException $e, string $operation, string $context): void
    {
        $err = $e->getError();

        $logContext = [
            'operation'        => $operation,
            'context'          => $context,
            'message'          => $e->getMessage(),
            'stripe_error_type' => $err->type ?? null,
            'stripe_code'      => $err->code ?? null,
            'decline_code'     => $err->decline_code ?? null,
            'param'            => $err->param ?? null,
            'http_status'      => $e->getHttpStatus(),
        ];

        if (in_array($err->type ?? '', ['card_error', 'invalid_request_error'])) {
            Log::error("StripeDepositService: {$operation} failed - client error", $logContext);
        } else {
            Log::error("StripeDepositService: {$operation} failed - server error", $logContext);
        }
    }

    /**
     * Clé d'idempotence avec option pour rendre unique
     */
    private function idemKey(string $op, string $identifier, int $amountCents, bool $unique = false): string
    {
        $base = "{$op}|{$identifier}|{$amountCents}|{$this->currency}";

        // CORRECTION : Ajouter un timestamp si on veut une clé unique
        if ($unique) {
            $base .= "|" . microtime(true);
        }

        $hash = substr(hash('sha256', $base), 0, 32);
        return "dep_{$op}_{$hash}";
    }
}
