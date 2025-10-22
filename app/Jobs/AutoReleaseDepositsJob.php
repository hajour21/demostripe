<?php
// app/Jobs/AutoReleaseDepositsJob.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Deposit;
use App\Services\StripeDepositService;
use Illuminate\Support\Facades\Log;

class AutoReleaseDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(StripeDepositService $stripeService)
    {
        $depositsToRelease = Deposit::where('status', 'authorized')
            ->whereHas('booking', function ($query) {
                $query->where('check_out_at', '<', now()->subDay());
            })
            ->get();

        foreach ($depositsToRelease as $deposit) {
            try {
                Log::info('Auto-releasing deposit', ['deposit_id' => $deposit->id]);
                
                $stripeService->release($deposit->stripe_payment_intent_id);
                $deposit->update([
                    'status' => 'released',
                    'released_at' => now()
                ]);
                
                Log::info('Deposit auto-released', ['deposit_id' => $deposit->id]);
            } catch (\Exception $e) {
                Log::error('Failed to auto-release deposit', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}