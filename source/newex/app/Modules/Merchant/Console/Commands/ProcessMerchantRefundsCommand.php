<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Modules\Merchant\Models\MerchantRefund;
use App\Modules\Merchant\Services\RefundService;
use App\Modules\Merchant\Services\RefundTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessMerchantRefundsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'merchant:process-refunds {--limit=10 : Maximum number of refunds to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process approved merchant refunds and send funds to destination addresses';

    protected RefundService $refundService;
    protected RefundTransferService $transferService;

    public function __construct(RefundService $refundService, RefundTransferService $transferService)
    {
        parent::__construct();
        $this->refundService = $refundService;
        $this->transferService = $transferService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Processing approved merchant refunds (limit: {$limit})...");

        // Get refunds ready for processing
        $refunds = $this->refundService->getRefundsForProcessing($limit);

        if ($refunds->isEmpty()) {
            $this->info('No refunds to process.');
            return Command::SUCCESS;
        }

        $this->info("Found {$refunds->count()} refunds to process.");

        $processed = 0;
        $failed = 0;

        foreach ($refunds as $refund) {
            $this->line("Processing refund {$refund->id}...");

            try {
                $result = $this->refundService->processRefund($refund);

                if ($result['success']) {
                    $this->info("  ✓ Refund {$refund->id} completed. TxHash: {$result['txn_hash']}");
                    $processed++;
                } else {
                    $this->error("  ✗ Refund {$refund->id} failed: {$result['error']}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Refund {$refund->id} exception: {$e->getMessage()}");
                Log::error('Refund processing exception', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $failed++;
            }

            // Small delay between refunds to avoid rate limiting
            usleep(500000); // 0.5 second
        }

        $this->info("Completed: {$processed} processed, {$failed} failed.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
