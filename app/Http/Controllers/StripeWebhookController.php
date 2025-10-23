<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    protected array $handledEvents = [
        'payment_intent.amount_capturable_updated',
        'payment_intent.canceled',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.partially_funded',
        'charge.refunded', // Pour les remboursements
    ];

    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        // Validation de configuration
        if (empty($webhookSecret)) {
            Log::critical('Stripe webhook secret not configured');
            return response()->json(['error' => 'Webhook not configured'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Vérification de la signature
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook: Invalid payload', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook: Invalid signature', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('Stripe webhook: Unexpected verification error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Verification failed'], Response::HTTP_BAD_REQUEST);
        }

        Log::info('Stripe webhook received', [
            'event_id' => $event->id,
            'type' => $event->type,
            'livemode' => $event->livemode,
        ]);

        // Vérification du doublon
        if (WebhookEvent::where('stripe_event_id', $event->id)->exists()) {
            Log::info('Stripe webhook: Duplicate event ignored', ['event_id' => $event->id]);
            return response()->json(['received' => true]);
        }

        // Enregistrement de l'événement
        $webhookEvent = WebhookEvent::create([
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'payload' => $event->data->object,
            'related_payment_intent_id' => $event->data->object->id ?? null,
            'livemode' => $event->livemode,
            'status' => 'received',
            'received_at' => now(),
        ]);

        // Traitement asynchrone
        if ($this->shouldProcessAsync($event->type)) {
            dispatch(fn() => $this->processWebhookEvent($event, $webhookEvent));
            Log::debug('Stripe webhook: Dispatched for async processing', ['event_id' => $event->id]);
        } else {
            // Traitement synchrone pour les événements critiques
            $this->processWebhookEvent($event, $webhookEvent);
        }

        return response()->json(['received' => true]);
    }

    protected function processWebhookEvent(\Stripe\Event $event, WebhookEvent $webhookEvent): void
    {
        $maxAttempts = 3;

        try {
            $webhookEvent->update([
                'status' => 'processing',
                'processing_started_at' => now(),
            ]);

            $this->handleEvent($event);

            $webhookEvent->update([
                'status' => 'processed',
                'processed_at' => now(),
                'processing_ended_at' => now(),
            ]);

            Log::info('Stripe webhook processed successfully', [
                'event_id' => $event->id,
                'type' => $event->type,
                'webhook_event_id' => $webhookEvent->id,
            ]);
        } catch (\Exception $e) {
            $attempts = $webhookEvent->attempts + 1;
            $shouldRetry = $attempts < $maxAttempts;

            $webhookEvent->update([
                'status' => $shouldRetry ? 'retrying' : 'failed',
                'last_error' => $e->getMessage(),
                'attempts' => $attempts,
                'last_attempt_at' => now(),
            ]);

            Log::error('Stripe webhook processing failed', [
                'event_id' => $event->id,
                'webhook_event_id' => $webhookEvent->id,
                'error' => $e->getMessage(),
                'attempt' => $attempts,
                'max_attempts' => $maxAttempts,
                'should_retry' => $shouldRetry,
            ]);

            if ($shouldRetry) {
                // Retry après un délai exponentiel
                $delay = now()->addMinutes($attempts * 5);
                dispatch(fn() => $this->processWebhookEvent($event, $webhookEvent))->delay($delay);
            }
        }
    }

    protected function handleEvent(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;

        if (!in_array($event->type, $this->handledEvents)) {
            Log::debug('Stripe webhook: Unhandled event type', [
                'type' => $event->type,
                'event_id' => $event->id,
            ]);
            return;
        }

        $deposit = Deposit::where('stripe_payment_intent_id', $paymentIntent->id)->first();

        if (!$deposit) {
            Log::warning('Stripe webhook: No deposit found for PaymentIntent', [
                'payment_intent_id' => $paymentIntent->id,
                'event_type' => $event->type,
                'event_id' => $event->id,
            ]);
            return;
        }

        Log::info('Stripe webhook: Processing event for deposit', [
            'deposit_id' => $deposit->id,
            'event_type' => $event->type,
            'current_status' => $deposit->status,
        ]);

        match ($event->type) {
            'payment_intent.amount_capturable_updated' => $this->handleAmountCapturable($deposit, $paymentIntent),
            'payment_intent.succeeded' => $this->handleSucceeded($deposit, $paymentIntent),
            'payment_intent.canceled' => $this->handleCanceled($deposit, $paymentIntent),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($deposit, $paymentIntent),
            'payment_intent.partially_funded' => $this->handlePartiallyFunded($deposit, $paymentIntent),
            'charge.refunded' => $this->handleChargeRefunded($deposit, $event->data->object),
            default => Log::debug('Stripe webhook: Unhandled event in match', ['type' => $event->type])
        };
    }

    protected function handleAmountCapturable(Deposit $deposit, \Stripe\PaymentIntent $paymentIntent): void
    {
        $deposit->update([
            'status' => Deposit::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'authorized_amount_cents' => $paymentIntent->amount_capturable ?? $paymentIntent->amount,
        ]);
    }

    protected function handleSucceeded(Deposit $deposit, \Stripe\PaymentIntent $paymentIntent): void
    {
        $deposit->update([
            'status' => Deposit::STATUS_CAPTURED,
            'captured_at' => now(),
            'captured_amount_cents' => $paymentIntent->amount_received,
        ]);
    }

    protected function handleCanceled(Deposit $deposit, \Stripe\PaymentIntent $paymentIntent): void
    {
        if (in_array($deposit->status, [Deposit::STATUS_PENDING, Deposit::STATUS_AUTHORIZED])) {
            $deposit->update([
                'status' => Deposit::STATUS_RELEASED,
                'released_at' => now(),
                'cancellation_reason' => $paymentIntent->cancellation_reason ?? null,
                'last_error' => $paymentIntent->last_payment_error->message ?? null,
            ]);
        }
    }

    protected function handlePaymentFailed(Deposit $deposit, \Stripe\PaymentIntent $paymentIntent): void
    {
        $deposit->update([
            'status' => Deposit::STATUS_FAILED,
            'last_error' => $paymentIntent->last_payment_error->message ?? 'Unknown payment error',
        ]);
    }

    protected function handlePartiallyFunded(Deposit $deposit, \Stripe\PaymentIntent $paymentIntent): void
    {
        // Gestion des paiements partiels si nécessaire
        Log::info('Stripe webhook: Partially funded payment intent', [
            'deposit_id' => $deposit->id,
            'amount_received' => $paymentIntent->amount_received,
        ]);
    }

    protected function handleChargeRefunded(Deposit $deposit, \Stripe\Charge $charge): void
    {
        // Gestion des remboursements
        Log::info('Stripe webhook: Charge refunded', [
            'deposit_id' => $deposit->id,
            'charge_id' => $charge->id,
            'refunded' => $charge->refunded,
        ]);
    }

    protected function shouldProcessAsync(string $eventType): bool
    {
        // Traitement synchrone pour les événements critiques
        $syncEvents = [
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
        ];

        return !in_array($eventType, $syncEvents);
    }
}
