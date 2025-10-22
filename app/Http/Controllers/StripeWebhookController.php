<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WebhookEvent;
use App\Models\Deposit;
use App\Services\StripeDepositService;
use Stripe\Webhook;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    protected array $handledEvents = [
        'payment_intent.amount_capturable_updated',
        'payment_intent.canceled',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.partially_funded',
    ];

    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        // Validation du secret webhook
        if (empty($webhookSecret)) {
            Log::error('Stripe webhook secret not configured');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            Log::error('Stripe webhook verification failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Verification failed'], 400);
        }

        // Enregistrement de l'événement
        $webhookEvent = WebhookEvent::create([
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'payload' => $event->data->object,
            'related_payment_intent_id' => $event->data->object->id ?? null,
            'status' => 'received',
        ]);

        // Traitement asynchrone si souhaité
        dispatch(fn() => $this->processWebhookEvent($event, $webhookEvent));

        return response()->json(['received' => true]);
    }

    protected function processWebhookEvent($event, WebhookEvent $webhookEvent): void
    {
        try {
            $webhookEvent->update(['status' => 'processing']);

            $this->handleEvent($event);

            $webhookEvent->update([
                'status' => 'processed',
                'processed_at' => now()
            ]);

            Log::info('Stripe webhook processed successfully', [
                'event_id' => $event->id,
                'type' => $event->type
            ]);
        } catch (\Exception $e) {
            $webhookEvent->update([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
                'attempts' => $webhookEvent->attempts + 1
            ]);

            Log::error('Stripe webhook processing failed', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
                'attempt' => $webhookEvent->attempts
            ]);
        }
    }

    protected function handleEvent($event): void
    {
        $paymentIntent = $event->data->object;
        $deposit = Deposit::where('stripe_payment_intent_id', $paymentIntent->id)->first();

        if (!$deposit) {
            Log::warning('Stripe webhook: No deposit found for PaymentIntent', [
                'payment_intent_id' => $paymentIntent->id,
                'event_type' => $event->type
            ]);
            return;
        }

        match ($event->type) {
            'payment_intent.amount_capturable_updated' => $this->handleAmountCapturable($deposit, $paymentIntent),
            'payment_intent.succeeded' => $this->handleSucceeded($deposit, $paymentIntent),
            'payment_intent.canceled' => $this->handleCanceled($deposit, $paymentIntent),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($deposit, $paymentIntent),
            default => Log::debug('Unhandled Stripe webhook event', ['type' => $event->type])
        };
    }

    protected function handleAmountCapturable(Deposit $deposit, $paymentIntent): void
    {
        $deposit->update([
            'status' => 'authorized',
            'authorized_at' => now(),
            'authorized_amount_cents' => $paymentIntent->amount_capturable
        ]);
    }

    protected function handleSucceeded(Deposit $deposit, $paymentIntent): void
    {
        $deposit->update([
            'status' => 'captured',
            'captured_at' => now(),
            'captured_amount_cents' => $paymentIntent->amount_received
        ]);
    }

    protected function handleCanceled(Deposit $deposit, $paymentIntent): void
    {
        if (in_array($deposit->status, ['pending', 'authorized'])) {
            $deposit->update([
                'status' => 'released',
                'released_at' => now(),
                'cancellation_reason' => $paymentIntent->cancellation_reason ?? null
            ]);
        }
    }

    protected function handlePaymentFailed(Deposit $deposit, $paymentIntent): void
    {
        $deposit->update([
            'status' => 'failed',
            'last_error' => $paymentIntent->last_payment_error->message ?? 'Unknown error'
        ]);
    }
}
