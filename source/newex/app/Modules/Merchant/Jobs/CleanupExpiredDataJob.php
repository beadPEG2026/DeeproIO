<?php

namespace App\Modules\Merchant\Jobs;

use App\Modules\Merchant\Services\MerchantAuthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupExpiredDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    /**
     * Execute the job.
     */
    public function handle(MerchantAuthService $authService): void
    {
        $results = [];

        // Cleanup expired nonces
        $results['nonces'] = $authService->cleanupExpiredNonces();

        // Cleanup old rate limits
        $results['rate_limits'] = DB::table('merchant_rate_limits')
            ->where('period_end', '<', now()->subDay())
            ->delete();

        // Cleanup old webhook attempts (keep last 30 days)
        $results['webhook_attempts'] = DB::table('merchant_webhook_attempts')
            ->where('attempted_at', '<', now()->subDays(30))
            ->delete();

        // Cleanup old timeline entries (keep last 90 days for non-paid invoices)
        $results['timeline'] = DB::table('merchant_invoice_timeline')
            ->where('occurred_at', '<', now()->subDays(90))
            ->whereNotIn('invoice_id', function ($query) {
                $query->select('id')
                    ->from('merchant_invoices')
                    ->whereIn('status', ['paid', 'overpaid', 'settled']);
            })
            ->delete();

        // Cleanup old address audit logs (keep last 180 days)
        $results['address_audit'] = DB::table('merchant_address_audit_logs')
            ->where('created_at', '<', now()->subDays(180))
            ->delete();

        $totalCleaned = array_sum($results);

        if ($totalCleaned > 0) {
            Log::info("Cleanup job completed", $results);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Cleanup job failed", [
            'error' => $exception->getMessage(),
        ]);
    }
}
