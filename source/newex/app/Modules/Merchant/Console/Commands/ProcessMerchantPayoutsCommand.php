<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Modules\Merchant\Models\MerchantPayout;
use App\Modules\Merchant\Services\PayoutService;
use App\Modules\Merchant\Services\PayoutTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessMerchantPayoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'merchant:process-payouts {--limit=10 : Maximum number of payouts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process approved merchant payouts and send funds to payout addresses';

    protected PayoutService $payoutService;
    protected PayoutTransferService $transferService;

    public function __construct(PayoutService $payoutService, PayoutTransferService $transferService)
    {
        parent::__construct();
        $this->payoutService = $payoutService;
        $this->transferService = $transferService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Processing approved merchant payouts (limit: {$limit})...");

        // Get approved payouts ready for processing
        $payouts = MerchantPayout::where('status', MerchantPayout::STATUS_APPROVED)
            ->with(['merchant', 'currency', 'network'])
            ->orderBy('approved_at', 'asc')
            ->limit($limit)
            ->get();

        if ($payouts->isEmpty()) {
            $this->info('No payouts to process.');
            return Command::SUCCESS;
        }

        $this->info("Found {$payouts->count()} payouts to process.");

        $processed = 0;
        $failed = 0;

        foreach ($payouts as $payout) {
            $this->line("Processing payout {$payout->id} ({$payout->reference})...");

            // Validate required fields
            if (!$payout->currency_id || !$payout->network_id) {
                $this->error("  ✗ Payout {$payout->id} missing currency or network");
                $failed++;
                continue;
            }

            if (!$payout->payout_address) {
                $this->error("  ✗ Payout {$payout->id} missing payout address");
                $failed++;
                continue;
            }

            try {
                $result = $this->transferService->processAndSendPayout($payout);

                if ($result['success']) {
                    $this->info("  ✓ Payout {$payout->id} completed. TxHash: {$result['txn_hash']}");
                    $processed++;
                } else {
                    $this->error("  ✗ Payout {$payout->id} failed: {$result['error']}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Payout {$payout->id} exception: {$e->getMessage()}");
                Log::error('Payout processing exception', [
                    'payout_id' => $payout->id,
                    'reference' => $payout->reference,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $failed++;
            }

            // Small delay between payouts to avoid rate limiting
            usleep(500000); // 0.5 second
        }

        $this->info("Completed: {$processed} processed, {$failed} failed.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
