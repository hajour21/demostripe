<?php

namespace App\Jobs;

use App\Models\Deposit;
use App\Services\StripeDepositService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AutoReleaseDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(StripeDepositService $stripeService): void
    {
        $deposits = Deposit::where('status', 'authorized')
            ->whereHas('booking', function ($query) {
                $query->where('check_out_at', '<', now()->subDay());
            })
            ->get();

        foreach ($deposits as $deposit) {
            try {
                DB::transaction(function () use ($deposit, $stripeService) {
                    $stripeService->release($deposit->stripe_payment_intent_id);
                    
                    $deposit->update([
                        'status' => 'released',
                        'released_at' => now(),
                    ]);
                });
            } catch (\Exception $e) {
                logger()->error("Auto-release failed for deposit {$deposit->id}: " . $e->getMessage());
            }
        }
    }
}