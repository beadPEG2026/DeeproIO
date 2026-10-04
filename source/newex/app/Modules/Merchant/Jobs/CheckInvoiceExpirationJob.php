<?php

namespace App\Modules\Merchant\Jobs;

use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Repositories\InvoiceRepository;
use App\Modules\Merchant\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckInvoiceExpirationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Execute the job.
     */
    public function handle(InvoiceRepository $invoiceRepository, InvoiceService $invoiceService): void
    {
        $expiredInvoices = $invoiceRepository->getExpiredInvoices();

        $count = 0;
        foreach ($expiredInvoices as $invoice) {
            try {
                $invoiceService->expireInvoice($invoice, 'Payment timeout');
                $count++;

                Log::info("Invoice expired by job", ['invoice_id' => $invoice->id]);
            } catch (\Exception $e) {
                Log::error("Failed to expire invoice", [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info("Invoice expiration job completed", ['expired_count' => $count]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Invoice expiration job failed", [
            'error' => $exception->getMessage(),
        ]);
    }
}
