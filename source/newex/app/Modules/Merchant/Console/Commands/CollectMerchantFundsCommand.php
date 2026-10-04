<?php

namespace App\Modules\Merchant\Console\Commands;

use App\Models\Network\Network;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use App\Modules\Merchant\Models\MerchantInvoicePayment;
use App\Modules\Merchant\Services\FundCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CollectMerchantFundsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'merchant:collect-funds
                            {--network= : Specific network to collect (erc, trc, bep, polygon, sol, btc)}
                            {--dry-run : Show what would be collected without actually collecting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collect merchant deposit funds to hot wallet';

    protected FundCollectionService $collectionService;

    public function __construct(FundCollectionService $collectionService)
    {
        parent::__construct();
        $this->collectionService = $collectionService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $network = $this->option('network');
        $dryRun = $this->option('dry-run');

        $this->info('Starting merchant fund collection...');

        if ($network) {
            $this->collectForNetwork($network, $dryRun);
        } else {
            // Collect for all networks
            $networks = ['erc', 'eth', 'trc', 'trx', 'bnb', 'bep', 'matic', 'matic20', 'sol', 'solspl', 'btc'];
            foreach ($networks as $net) {
                $this->collectForNetwork($net, $dryRun);
            }
        }

        $this->info('Fund collection complete.');
        return 0;
    }

    /**
     * Collect funds for a specific network
     */
    protected function collectForNetwork(string $networkSlug, bool $dryRun): void
    {
        $networkId = $this->getNetworkId($networkSlug);
        if (!$networkId) {
            $this->warn("Unknown network: {$networkSlug}");
            return;
        }

        $hotWallet = $this->collectionService->getHotWalletAddress($networkId);
        if (!$hotWallet && $networkSlug !== "btc") {
            $this->warn("No hot wallet configured for network: {$networkSlug}");
            return;
        }

        $this->info("Collecting {$networkSlug} funds to hot wallet: {$hotWallet}");

        // Get addresses marked for sweep
        $addresses = MerchantDepositAddress::where('network_id', $networkId)
            ->where('is_sweep_required', true)
            ->whereNull('swept_at')
            ->where('status', 'used')
            ->whereNotNull('private_key')
            ->with(['invoice.currencyModel'])
            ->get();

        if ($addresses->isEmpty()) {
            $this->line("  No addresses need collection for {$networkSlug}");
            return;
        }

        $this->info("  Found {$addresses->count()} addresses to collect");

        foreach ($addresses as $address) {
            $this->processAddress($address, $networkSlug, $dryRun);
        }
    }

    /**
     * Process a single address for collection
     */
    protected function processAddress(MerchantDepositAddress $address, string $networkSlug, bool $dryRun): void
    {
        // Get uncollected confirmed payments for this address
        $payments = MerchantInvoicePayment::where('deposit_address_id', $address->id)
            ->where('status', 'confirmed')
            ->whereNull('collected_at')
            ->with(['invoice.currencyModel'])
            ->get();

        if ($payments->isEmpty()) {
            $this->line("    Address {$address->address}: No uncollected payments");
            // Mark as swept if no pending payments
            if (!$dryRun) {
                $address->update(['is_sweep_required' => false]);
            }
            return;
        }

        // Group payments by currency
        $balances = [];
        foreach ($payments as $payment) {
            $currency = $payment->invoice->currencyModel ?? null;
            if (!$currency) {
                continue;
            }

            $currencyId = $currency->id;
            if (!isset($balances[$currencyId])) {
                $balances[$currencyId] = [
                    'currency' => $currency,
                    'amount' => '0',
                    'payments' => [],
                ];
            }
            $balances[$currencyId]['amount'] = bcadd($balances[$currencyId]['amount'], $payment->amount_crypto, 18);
            $balances[$currencyId]['payments'][] = $payment;
        }

        foreach ($balances as $currencyId => $balance) {
            $currency = $balance['currency'];
            $amount = $balance['amount'];

            $this->line("    Address {$address->address}: {$amount} {$currency->symbol}");

            if ($dryRun) {
                $this->line("      [DRY RUN] Would collect to hot wallet");
                continue;
            }

            $result = $this->collectFunds($address, $currency, $amount, $networkSlug);

            if ($result['success']) {
                $txHash = $result['data']['txHash'] ?? $result['data']['hash'] ?? $result['data']['signature'] ?? false;
                $this->info("      Collected! TX: {$txHash}");

                // Mark as swept
                $this->collectionService->markAsSwept($address, $txHash);

                if($txHash) {
                    Log::info('Merchant funds collected', [
                        'address' => $address->address,
                        'currency' => $currency->symbol,
                        'amount' => $amount,
                        'tx_hash' => $txHash,
                    ]);
                }
            } else {
                $this->error("      Collection failed: " . ($result['error'] ?? 'Unknown error'));

                Log::error('Merchant fund collection failed', [
                    'address' => $address->address,
                    'currency' => $currency->symbol,
                    'amount' => $amount,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }

            // Rate limiting
            sleep(2);
        }
    }

    /**
     * Collect funds based on network type
     */
    protected function collectFunds(MerchantDepositAddress $address, $currency, string $amount, string $networkSlug): array
    {
        return match ($networkSlug) {
            'erc', 'eth' => $this->collectionService->collectErcFunds($address, $currency, $amount, $networkSlug),
            'trc', 'trx' => $this->collectionService->collectTrcFunds($address, $currency, $amount, $networkSlug),
            'bep', 'bsc', 'bnb' => $this->collectionService->collectBepFunds($address, $currency, $amount, $networkSlug),
            'polygon', 'matic' => $this->collectionService->collectPolygonFunds($address, $currency, $amount, $networkSlug),
            'sol' => $this->collectionService->collectSolanaFunds($address, $currency, $amount, false),
            'solspl' => $this->collectionService->collectSolanaFunds($address, $currency, $amount, true),
            'btc' => $this->collectionService->collectBitcoinFunds($address, $currency, $amount),
            default => ['success' => false, 'error' => "Unsupported network: {$networkSlug}"],
        };
    }

    /**
     * Get network ID from slug
     */
    protected function getNetworkId(string $slug): ?int
    {
        $mapping = [
            'erc' => NETWORK_ERC,
            'eth' => NETWORK_ETH,
            'trc' => NETWORK_TRC,
            'trx' => NETWORK_TRX,
            'bep' => NETWORK_BEP,
            'bnb' => NETWORK_BNB,
            'matic20' => NETWORK_MATIC20,
            'matic' => NETWORK_MATIC,
            'sol' => NETWORK_SOL,
            'solspl' => NETWORK_SOL_SPL,
            'btc' => NETWORK_BTC,
        ];

        return $mapping[strtolower($slug)] ?? null;
    }
}
